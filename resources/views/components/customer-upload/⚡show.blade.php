<?php

use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\UploadedDocument;
use App\Models\UploadLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use ZipArchive;

new #[Layout(
    'layouts.public',
    ['title' => 'Secure Document Upload']
)] class extends Component
{
    use WithFileUploads;

    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    /*
     * The database ID is resolved from the secure URL token.
     * Locked prevents browser-side modification of this value.
     */
    #[Locked]
    public int $uploadLinkId;

    /*
     * Files are indexed using the request-item ID:
     *
     * $uploads[12] = passport file
     * $uploads[13] = Emirates ID file
     */
    public array $uploads = [];

    public bool $submissionComplete = false;

    public function mount(string $token): void
    {
        /*
         * The raw token is never searched directly.
         * Only its SHA-256 hash is stored and used for lookup.
         */
        $uploadLink = UploadLink::query()
            ->where('token_hash', hash('sha256', $token))
            ->firstOrFail();

        $this->uploadLinkId = $uploadLink->id;

        $this->recordLinkAccess();
    }

    /*
     * Record only the first customer access in the activity timeline,
     * while incrementing the link access counter on every page opening.
     */
    private function recordLinkAccess(): void
    {
        DB::transaction(function (): void {
            $uploadLink = UploadLink::query()
                ->with('documentRequest')
                ->lockForUpdate()
                ->findOrFail($this->uploadLinkId);

            $isFirstAccess =
                $uploadLink->first_accessed_at === null;

            $accessedAt = now();

            $uploadLink->increment('access_count');

            $uploadLink->forceFill([
                'first_accessed_at' =>
                    $uploadLink->first_accessed_at
                    ?? $accessedAt,

                'last_accessed_at' => $accessedAt,
            ])->save();

            if (! $isFirstAccess) {
                return;
            }

            $documentRequest = $uploadLink->documentRequest;

            if ($documentRequest->viewed_at === null) {
                $documentRequest->update([
                    'viewed_at' => $accessedAt,
                ]);
            }

            $documentRequest->activities()->create([
                'actor_type' => 'customer',
                'activity_type' => 'link_opened',

                'description' =>
                    'The customer opened the secure document upload link.',

                'subject_type' => UploadLink::class,
                'subject_id' => $uploadLink->id,

                'ip_address' => request()->ip(),

                'user_agent' => Str::limit(
                    (string) request()->userAgent(),
                    2000,
                    ''
                ),

                'occurred_at' => $accessedAt,
            ]);
        });
    }

    public function submitDocuments(): void
    {
        $this->resetValidation();

        $uploadLink = $this->loadUploadLink();

        if (! $uploadLink->isUsable()) {
            $this->addError(
                'link',
                'This secure upload link is no longer available.'
            );

            return;
        }

        $documentRequest = $uploadLink->documentRequest;

        if (! $documentRequest->canReceiveUploads()) {
            $this->addError(
                'link',
                'This document request is no longer accepting uploads.'
            );

            return;
        }

        /*
         * Reload the request checklist with any previously received files.
         * A required item does not need another file if it was already received.
         */
        $documentRequest->load([
            'items' => fn ($query) => $query
                ->orderBy('sort_order'),

            'items.uploadedDocuments' => fn ($query) => $query
                ->where('is_current', true),
        ]);

        $preparedFiles = [];
        $validationFailed = false;

        foreach ($documentRequest->items as $item) {
            $file = $this->uploads[$item->id] ?? null;

            $alreadyReceived =
                $item->uploadedDocuments->isNotEmpty();

            if (
                $item->is_required
                && ! $alreadyReceived
                && ! $file
            ) {
                $this->addError(
                    'uploads.'.$item->id,
                    'Please select this required document.'
                );

                $validationFailed = true;

                continue;
            }

            if (! $file) {
                continue;
            }

            if (! $file instanceof TemporaryUploadedFile) {
                $this->addError(
                    'uploads.'.$item->id,
                    'The selected upload is invalid. Please select it again.'
                );

                $validationFailed = true;

                continue;
            }

            try {
                /*
                 * Inspect the physical temporary file directly.
                 *
                 * This avoids the TemporaryUploadedFile::getSize()
                 * metadata call that is failing in the current environment.
                 */
                $inspection =
                    $this->inspectTemporaryFile($file);

                $preparedFiles[] = [
                    'item_id' => $item->id,

                    'absolute_path' =>
                        $inspection['absolute_path'],

                    'original_filename' =>
                        $inspection['original_filename'],

                    'extension' =>
                        $inspection['extension'],

                    'mime_type' =>
                        $inspection['mime_type'],

                    'size_bytes' =>
                        $inspection['size_bytes'],

                    'checksum_sha256' =>
                        $inspection['checksum_sha256'],
                ];
            } catch (RuntimeException $exception) {
                $this->addError(
                    'uploads.'.$item->id,
                    $exception->getMessage()
                );

                $validationFailed = true;
            }
        }

        if ($validationFailed) {
            return;
        }

        if ($preparedFiles === []) {
            $this->addError(
                'uploads',
                'Select at least one document before submitting.'
            );

            return;
        }

        /*
         * Permanent file locations created before the database operation
         * are tracked here so they can be removed if the transaction fails.
         */
        $storedFiles = [];

        try {
            foreach ($preparedFiles as $preparedFile) {
                $storedFilename =
                    (string) Str::uuid()
                    .'.'
                    .$preparedFile['extension'];

                $directory =
                    'smartserve/document-requests/'
                    .$documentRequest->id
                    .'/items/'
                    .$preparedFile['item_id'];

                $storagePath =
                    $directory.'/'.$storedFilename;

                /*
                 * Stream the verified temporary file into private storage.
                 * The customer filename is never used as the storage filename.
                 */
                $stream = fopen(
                    $preparedFile['absolute_path'],
                    'rb'
                );

                if ($stream === false) {
                    throw new RuntimeException(
                        'The temporary document could not be opened.'
                    );
                }

                try {
                    $stored = Storage::disk('local')->put(
                        $storagePath,
                        $stream
                    );
                } finally {
                    fclose($stream);
                }

                if (! $stored) {
                    throw new RuntimeException(
                        'The document could not be stored.'
                    );
                }

                $storedFiles[] = [
                    ...$preparedFile,

                    'storage_disk' => 'local',
                    'storage_path' => $storagePath,
                    'stored_filename' => $storedFilename,
                ];
            }

            DB::transaction(
                function () use (
                    $storedFiles,
                    $documentRequest
                ): void {
                    /*
                     * Lock the link so simultaneous submissions cannot
                     * bypass a one-time or limited-upload restriction.
                     */
                    $lockedLink = UploadLink::query()
                        ->lockForUpdate()
                        ->findOrFail($this->uploadLinkId);

                    if (! $lockedLink->isUsable()) {
                        throw new RuntimeException(
                            'This secure upload link is no longer available.'
                        );
                    }

                    $lockedRequest = DocumentRequest::query()
                        ->lockForUpdate()
                        ->findOrFail($documentRequest->id);

                    if (! $lockedRequest->canReceiveUploads()) {
                        throw new RuntimeException(
                            'This request is no longer accepting uploads.'
                        );
                    }

                    foreach ($storedFiles as $storedFile) {
                        /*
                         * Confirm the document item belongs to this exact
                         * request instead of trusting the submitted item ID.
                         */
                        $item = DocumentRequestItem::query()
                            ->where(
                                'document_request_id',
                                $lockedRequest->id
                            )
                            ->whereKey(
                                $storedFile['item_id']
                            )
                            ->lockForUpdate()
                            ->firstOrFail();

                        $nextVersion =
                            (
                                UploadedDocument::withTrashed()
                                    ->where(
                                        'document_request_item_id',
                                        $item->id
                                    )
                                    ->max('version_number')
                                ?? 0
                            ) + 1;

                        /*
                         * Preserve previous uploads as history.
                         * Only the newly received file becomes current.
                         */
                        UploadedDocument::query()
                            ->where(
                                'document_request_item_id',
                                $item->id
                            )
                            ->where('is_current', true)
                            ->update([
                                'is_current' => false,
                            ]);

                        UploadedDocument::create([
                            'document_request_id' =>
                                $lockedRequest->id,

                            'document_request_item_id' =>
                                $item->id,

                            'upload_link_id' =>
                                $lockedLink->id,

                            'uploaded_by' => null,
                            'upload_source' => 'customer',

                            'storage_disk' =>
                                $storedFile['storage_disk'],

                            'storage_path' =>
                                $storedFile['storage_path'],

                            'original_filename' =>
                                $storedFile['original_filename'],

                            'stored_filename' =>
                                $storedFile['stored_filename'],

                            'mime_type' =>
                                $storedFile['mime_type'],

                            'extension' =>
                                $storedFile['extension'],

                            'size_bytes' =>
                                $storedFile['size_bytes'],

                            'checksum_sha256' =>
                                $storedFile['checksum_sha256'],

                            'version_number' => $nextVersion,
                            'is_current' => true,

                            'status' => 'pending_review',

                            /*
                             * A queued antivirus job will update this later.
                             */
                            'security_scan_status' => 'pending',

                            'uploaded_at' => now(),
                        ]);

                        /*
                         * Replacement uploads return the checklist item
                         * to staff review.
                         */
                        $item->update([
                            'status' => 'uploaded',

                            'received_at' =>
                                $item->received_at ?? now(),

                            'review_notes' => null,
                            'reviewed_by' => null,
                            'approved_at' => null,
                            'rejected_at' => null,
                        ]);
                    }

                    /*
                     * One submission may contain multiple files.
                     * The counter records submissions, not file count.
                     */
                    $lockedLink->increment('upload_count');

                    $requiredCount = $lockedRequest
                        ->items()
                        ->where('is_required', true)
                        ->count();

                    $receivedRequiredCount = $lockedRequest
                        ->items()
                        ->where('is_required', true)
                        ->whereIn(
                            'status',
                            [
                                'uploaded',
                                'under_review',
                                'approved',
                                'rejected',
                                'replacement_required',
                            ]
                        )
                        ->count();

                    $allRequiredReceived =
                        $requiredCount === 0
                        || $receivedRequiredCount
                            >= $requiredCount;

                    /*
                     * Only collection-phase requests are moved automatically.
                     * A late upload must not reverse an approved or externally
                     * processing service request.
                     */
                    $collectionStatuses = [
                        'draft',
                        'waiting_for_documents',
                        'partially_received',
                        'action_required',
                        'additional_documents_required',
                    ];

                    $oldStatus = $lockedRequest->status;
                    $newStatus = $oldStatus;

                    if (
                        in_array(
                            $oldStatus,
                            $collectionStatuses,
                            true
                        )
                    ) {
                        $newStatus = $allRequiredReceived
                            ? 'documents_received'
                            : 'partially_received';
                    }

                    $requestUpdates = [
                        'status' => $newStatus,
                    ];

                    if (
                        $allRequiredReceived
                        && $lockedRequest
                            ->documents_received_at === null
                    ) {
                        $requestUpdates[
                            'documents_received_at'
                        ] = now();
                    }

                    $lockedRequest->update(
                        $requestUpdates
                    );

                    $lockedRequest->activities()->create([
                        'actor_type' => 'customer',

                        'activity_type' =>
                            'documents_uploaded',

                        'description' =>
                            count($storedFiles)
                            .' document(s) uploaded by the customer.',

                        'from_status' =>
                            $oldStatus !== $newStatus
                                ? $oldStatus
                                : null,

                        'to_status' =>
                            $oldStatus !== $newStatus
                                ? $newStatus
                                : null,

                        'metadata' => [
                            'file_count' =>
                                count($storedFiles),

                            'document_item_ids' =>
                                collect($storedFiles)
                                    ->pluck('item_id')
                                    ->values()
                                    ->all(),
                        ],

                        'ip_address' => request()->ip(),

                        'user_agent' => Str::limit(
                            (string) request()->userAgent(),
                            2000,
                            ''
                        ),

                        'occurred_at' => now(),
                    ]);
                },
                3
            );
        } catch (\Throwable $exception) {
            /*
             * Remove permanent files created before a failed transaction.
             */
            Storage::disk('local')->delete(
                collect($storedFiles)
                    ->pluck('storage_path')
                    ->all()
            );

            report($exception);

            $this->addError(
                'uploads',
                $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'The documents could not be saved. Please try again.'
            );

            return;
        }

        $this->reset('uploads');
        $this->resetValidation();

        $this->submissionComplete = true;
    }

    /*
     * Resolve the real physical file without calling getSize(),
     * getMimeType() or Laravel's File validation rule.
     */
    private function resolveTemporaryPath(
        TemporaryUploadedFile $file
    ): string {
        $realPath = $file->getRealPath();

        if (
            is_string($realPath)
            && is_file($realPath)
        ) {
            return $realPath;
        }

        $diskName =
            config('livewire.temporary_file_upload.disk')
            ?: config('filesystems.default', 'local');

        $directory = trim(
            (string) (
                config(
                    'livewire.temporary_file_upload.directory'
                )
                ?: 'livewire-tmp'
            ),
            '/'
        );

        $temporaryFilename =
            basename($file->getFilename());

        $absolutePath = Storage::disk($diskName)->path(
            $directory.'/'.$temporaryFilename
        );

        if (! is_file($absolutePath)) {
            throw new RuntimeException(
                'The temporary upload could not be found. Please select the file again.'
            );
        }

        return $absolutePath;
    }

    /*
     * Validate the physical file using file signatures instead of
     * trusting the customer-provided extension or filename.
     */
    private function inspectTemporaryFile(
        TemporaryUploadedFile $file
    ): array {
        $absolutePath =
            $this->resolveTemporaryPath($file);

        $size = filesize($absolutePath);

        if ($size === false) {
            throw new RuntimeException(
                'The selected file size could not be verified.'
            );
        }

        if ($size <= 0) {
            throw new RuntimeException(
                'The selected file is empty.'
            );
        }

        if ($size > self::MAX_FILE_SIZE) {
            throw new RuntimeException(
                'Each document must not exceed 10 MB.'
            );
        }

        $originalFilename = Str::limit(
            basename($file->getClientOriginalName()),
            255,
            ''
        );

        $clientExtension = strtolower(
            pathinfo(
                $originalFilename,
                PATHINFO_EXTENSION
            )
        );

        $detectedType =
            $this->detectDocumentType(
                $absolutePath,
                $clientExtension
            );

        if ($detectedType === null) {
            throw new RuntimeException(
                'Only valid PDF, JPG, PNG, DOC and DOCX files are accepted.'
            );
        }

        $allowedClientExtensions = match (
            $detectedType['extension']
        ) {
            'jpg' => ['jpg', 'jpeg'],

            default => [
                $detectedType['extension'],
            ],
        };

        if (
            ! in_array(
                $clientExtension,
                $allowedClientExtensions,
                true
            )
        ) {
            throw new RuntimeException(
                'The file extension does not match the document contents.'
            );
        }

        $checksum = hash_file(
            'sha256',
            $absolutePath
        );

        if ($checksum === false) {
            throw new RuntimeException(
                'The document checksum could not be calculated.'
            );
        }

        return [
            'absolute_path' => $absolutePath,
            'original_filename' => $originalFilename,
            'extension' => $detectedType['extension'],
            'mime_type' => $detectedType['mime_type'],
            'size_bytes' => $size,
            'checksum_sha256' => $checksum,
        ];
    }

    /*
     * Detect common document formats from their binary signatures.
     *
     * DOCX receives additional verification by checking that the ZIP
     * container contains the normal Microsoft Word document structure.
     */
    private function detectDocumentType(
        string $absolutePath,
        string $clientExtension
    ): ?array {
        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $header = fread($handle, 8);
        } finally {
            fclose($handle);
        }

        if ($header === false) {
            return null;
        }

        $hexHeader = strtolower(
            bin2hex($header)
        );

        if (str_starts_with($header, '%PDF-')) {
            return [
                'extension' => 'pdf',
                'mime_type' => 'application/pdf',
            ];
        }

        if (str_starts_with($hexHeader, 'ffd8ff')) {
            return [
                'extension' => 'jpg',
                'mime_type' => 'image/jpeg',
            ];
        }

        if (
            str_starts_with(
                $hexHeader,
                '89504e470d0a1a0a'
            )
        ) {
            return [
                'extension' => 'png',
                'mime_type' => 'image/png',
            ];
        }

        /*
         * Legacy Microsoft DOC files use the OLE compound-file header.
         */
        if (
            str_starts_with(
                $hexHeader,
                'd0cf11e0a1b11ae1'
            )
            && $clientExtension === 'doc'
        ) {
            return [
                'extension' => 'doc',
                'mime_type' => 'application/msword',
            ];
        }

        /*
         * DOCX is a ZIP archive. Validate the internal Word structure
         * so an arbitrary ZIP file cannot be renamed to .docx.
         */
        if (
            str_starts_with($hexHeader, '504b')
            && $clientExtension === 'docx'
        ) {
            $zip = new ZipArchive();

            $opened = $zip->open(
                $absolutePath,
                ZipArchive::RDONLY
            );

            if ($opened !== true) {
                return null;
            }

            try {
                $hasContentTypes =
                    $zip->locateName(
                        '[Content_Types].xml'
                    ) !== false;

                $hasWordDocument =
                    $zip->locateName(
                        'word/document.xml'
                    ) !== false;
            } finally {
                $zip->close();
            }

            if (
                $hasContentTypes
                && $hasWordDocument
            ) {
                return [
                    'extension' => 'docx',

                    'mime_type' =>
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                ];
            }
        }

        return null;
    }

    public function uploadMore(): void
    {
        $uploadLink = $this->loadUploadLink();

        if (! $uploadLink->isUsable()) {
            return;
        }

        $this->submissionComplete = false;
        $this->reset('uploads');
        $this->resetValidation();
    }

    private function loadUploadLink(): UploadLink
    {
        return UploadLink::query()
            ->with([
                'documentRequest.serviceTemplate',

                'documentRequest.items' =>
                    fn ($query) => $query
                        ->orderBy('sort_order'),

                'documentRequest.items.uploadedDocuments' =>
                    fn ($query) => $query
                        ->where('is_current', true)
                        ->latest('uploaded_at'),
            ])
            ->findOrFail($this->uploadLinkId);
    }

    /*
     * Only customer-facing information is returned.
     * Internal notes, staff details and communication records are excluded.
     */
    public function with(): array
    {
        $uploadLink = $this->loadUploadLink();

        $documentRequest =
            $uploadLink->documentRequest;

        $linkAvailable =
            $uploadLink->isUsable()
            && $documentRequest->canReceiveUploads();

        $unavailableReason = null;

        if ($uploadLink->isInvalidated()) {
            $unavailableReason =
                'This secure upload link has been closed.';
        } elseif ($uploadLink->isExpired()) {
            $unavailableReason =
                'This secure upload link has expired.';
        } elseif ($uploadLink->hasReachedUploadLimit()) {
            $unavailableReason =
                'This secure upload link has already been used.';
        } elseif (! $documentRequest->canReceiveUploads()) {
            $unavailableReason =
                'This document request is no longer accepting uploads.';
        }

        return [
            'uploadLink' => $uploadLink,
            'requestRecord' => $documentRequest,
            'linkAvailable' => $linkAvailable,
            'unavailableReason' => $unavailableReason,
        ];
    }
};
?>

<div class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
    @if ($submissionComplete)
        <div class="mx-auto max-w-2xl rounded-2xl border border-green-200 bg-white p-6 text-center shadow-sm sm:p-10 dark:border-green-900 dark:bg-zinc-900">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-green-100 text-2xl text-green-700 dark:bg-green-950 dark:text-green-300">
                ✓
            </div>

            <h1 class="mt-5 text-2xl font-semibold">
                Documents submitted
            </h1>

            <p class="mt-3 text-zinc-600 dark:text-zinc-400">
                Your documents were securely received for
                <strong>
                    {{ $requestRecord->serviceTemplate->name }}
                </strong>.
            </p>

            <div class="mt-5 rounded-lg bg-zinc-50 px-4 py-3 text-sm text-zinc-600 dark:bg-zinc-950 dark:text-zinc-400">
                Request reference:
                <strong>
                    {{ $requestRecord->request_number }}
                </strong>
            </div>

            <p class="mt-5 text-sm text-zinc-500">
                SmartServe staff will review the files and contact you
                if another document or replacement is needed.
            </p>

            @if ($uploadLink->isUsable())
                <button
                    type="button"
                    wire:click="uploadMore"
                    class="mt-6 rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
                >
                    Upload Additional Documents
                </button>
            @endif
        </div>
    @elseif (! $linkAvailable)
        <div class="mx-auto max-w-2xl rounded-2xl border border-zinc-200 bg-white p-6 text-center shadow-sm sm:p-10 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-zinc-100 text-2xl text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                !
            </div>

            <h1 class="mt-5 text-2xl font-semibold">
                Upload link unavailable
            </h1>

            <p class="mt-3 text-zinc-600 dark:text-zinc-400">
                {{ $unavailableReason }}
            </p>

            <p class="mt-5 text-sm text-zinc-500">
                Contact the SmartServe staff member who sent this link
                to request a new secure upload link.
            </p>
        </div>
    @else
        <div class="space-y-6">
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-sm font-medium text-zinc-500">
                            Document request for
                        </p>

                        <h1 class="mt-1 text-2xl font-semibold">
                            {{ $requestRecord->customer_name }}
                        </h1>

                        <p class="mt-2 text-zinc-600 dark:text-zinc-400">
                            {{ $requestRecord->serviceTemplate->name }}
                        </p>
                    </div>

                    <div class="rounded-lg bg-zinc-50 px-4 py-3 text-sm dark:bg-zinc-950">
                        <div class="text-zinc-500">
                            Request reference
                        </div>

                        <div class="mt-1 font-semibold">
                            {{ $requestRecord->request_number }}
                        </div>
                    </div>
                </div>

                @if ($requestRecord->customer_instructions)
                    <div class="mt-6 rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950">
                        <h2 class="text-sm font-semibold text-blue-900 dark:text-blue-200">
                            Instructions
                        </h2>

                        <div class="mt-2 text-sm text-blue-800 dark:text-blue-300">
                            {!! nl2br(
                                e(
                                    $requestRecord
                                        ->customer_instructions
                                )
                            ) !!}
                        </div>
                    </div>
                @endif

                <div class="mt-5 flex flex-wrap gap-x-6 gap-y-2 text-sm text-zinc-500">
                    <span>
                        Expires:
                        {{ $uploadLink->expires_at->format(
                            'd M Y, H:i'
                        ) }}
                    </span>

                    <span>
                        Accepted:
                        PDF, JPG, PNG, DOC, DOCX
                    </span>

                    <span>
                        Maximum:
                        10 MB per file
                    </span>
                </div>
            </div>

            <form wire:submit="submitDocuments" class="space-y-6">
                @error('link')
                    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
                        {{ $message }}
                    </div>
                @enderror

                @error('uploads')
                    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
                        {{ $message }}
                    </div>
                @enderror

                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="mb-6">
                        <h2 class="text-lg font-semibold">
                            Required Documents
                        </h2>

                        <p class="mt-1 text-sm text-zinc-500">
                            Select the correct file for each document.
                        </p>
                    </div>

                    <div class="space-y-5">
                        @foreach ($requestRecord->items as $item)
                            @php
                                $currentUpload =
                                    $item->uploadedDocuments->first();
                            @endphp

                            <div
                                wire:key="customer-upload-item-{{ $item->id }}"
                                class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700"
                            >
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-semibold">
                                        {{ $item->name }}
                                    </h3>

                                    @if ($item->is_required)
                                        <span class="rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-700 dark:bg-red-950 dark:text-red-300">
                                            Required
                                        </span>
                                    @else
                                        <span class="rounded-full bg-zinc-100 px-2 py-1 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                            Optional
                                        </span>
                                    @endif

                                    @if ($currentUpload)
                                        <span class="rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700 dark:bg-green-950 dark:text-green-300">
                                            Previously Received
                                        </span>
                                    @endif
                                </div>

                                @if ($item->customer_instructions)
                                    <p class="mt-2 text-sm text-zinc-500">
                                        {{ $item->customer_instructions }}
                                    </p>
                                @endif

                                <div class="mt-4">
                                    <label
                                        for="upload-{{ $item->id }}"
                                        class="block text-sm font-medium"
                                    >
                                        @if ($currentUpload)
                                            Upload a replacement or updated file
                                        @else
                                            Select file
                                        @endif
                                    </label>

                                    <input
                                        id="upload-{{ $item->id }}"
                                        type="file"
                                        wire:model="uploads.{{ $item->id }}"
                                        accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                        class="mt-2 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm file:mr-4 file:rounded-md file:border-0 file:bg-zinc-900 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-zinc-700 dark:border-zinc-700 dark:bg-zinc-950 dark:file:bg-white dark:file:text-zinc-900"
                                    >

                                    <div
                                        wire:loading
                                        wire:target="uploads.{{ $item->id }}"
                                        class="mt-2 text-sm text-blue-600"
                                    >
                                        Preparing file...
                                    </div>

                                    @if (isset($uploads[$item->id]))
                                        <p class="mt-2 text-sm text-green-600">
                                            Selected:
                                            {{ $uploads[
                                                $item->id
                                            ]->getClientOriginalName() }}
                                        </p>
                                    @endif

                                    @error('uploads.'.$item->id)
                                        <p class="mt-2 text-sm text-red-600">
                                            {{ $message }}
                                        </p>
                                    @enderror
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="text-sm text-zinc-500">
                        By submitting, you confirm that the selected
                        documents belong to this request and are ready
                        for review by SmartServe staff.
                    </p>

                    <div class="mt-5 flex justify-end">
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="submitDocuments"
                            class="rounded-lg bg-zinc-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-zinc-700 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-zinc-900"
                        >
                            <span
                                wire:loading.remove
                                wire:target="submitDocuments"
                            >
                                Submit Documents
                            </span>

                            <span
                                wire:loading
                                wire:target="submitDocuments"
                            >
                                Saving Documents...
                            </span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    @endif
</div>
