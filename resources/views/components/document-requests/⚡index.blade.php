<?php

use App\Models\DocumentRequest;
use App\Models\ServiceTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public bool $showCreateForm = false;

    public string $service_template_id = '';

    public string $customer_name = '';

    public string $customer_mobile = '';

    public string $customer_email = '';

    public string $company_name = '';

    public string $assigned_to = '';

    public string $priority = 'normal';

    public string $preferred_channel = 'whatsapp';

    public string $due_date = '';

    public string $next_action = '';

    public string $customer_instructions = '';

    public string $internal_notes = '';

    public string $processing_days = '';

    public string $link_expiry_days = '7';

    public bool $allow_multiple_uploads = true;

    public array $documents = [];

    public function mount(): void
    {
        /*
         * Assign the current user by default when creating a request.
         */
        $this->assigned_to = (string) auth()->id();
    }

    public function openCreateForm(): void
    {
        $this->showCreateForm = true;

        if ($this->assigned_to === '') {
            $this->assigned_to = (string) auth()->id();
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    /*
     * When staff selects a service, copy its defaults and document
     * checklist into the request form.
     */
    public function updatedServiceTemplateId(string $value): void
    {
        $this->resetValidation([
            'service_template_id',
            'documents',
        ]);

        if ($value === '') {
            $this->documents = [];
            $this->customer_instructions = '';
            $this->processing_days = '';
            $this->link_expiry_days = '7';
            $this->allow_multiple_uploads = true;
            $this->due_date = '';

            return;
        }

        $serviceTemplate = ServiceTemplate::query()
            ->with([
                'requiredDocuments' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('sort_order'),
            ])
            ->where('is_active', true)
            ->find($value);

        if (! $serviceTemplate) {
            $this->service_template_id = '';
            $this->documents = [];

            $this->addError(
                'service_template_id',
                'The selected service template is unavailable.'
            );

            return;
        }

        $this->customer_instructions =
            $serviceTemplate->customer_instructions ?? '';

        $this->processing_days =
            $serviceTemplate->default_processing_days !== null
                ? (string) $serviceTemplate->default_processing_days
                : '';

        $this->link_expiry_days =
            (string) $serviceTemplate->default_link_expiry_days;

        $this->allow_multiple_uploads =
            (bool) $serviceTemplate->allow_multiple_uploads;

        if ($serviceTemplate->default_processing_days !== null) {
            $this->due_date = now()
                ->addDays($serviceTemplate->default_processing_days)
                ->toDateString();
        } else {
            $this->due_date = '';
        }

        /*
         * Copy the template documents into editable request rows.
         * Later changes to the service template will not alter an
         * already-created request.
         */
        $this->documents = $serviceTemplate
            ->requiredDocuments
            ->map(fn ($document) => [
                'service_template_document_id' => $document->id,
                'name' => $document->name,
                'description' => $document->description ?? '',
                'customer_instructions' =>
                    $document->customer_instructions ?? '',
                'is_required' => (bool) $document->is_required,
            ])
            ->values()
            ->all();

        if ($this->documents === []) {
            $this->documents = [
                $this->emptyDocumentRow(),
            ];
        }
    }

    public function addDocumentRow(): void
    {
        $this->documents[] = $this->emptyDocumentRow();
    }

    public function removeDocumentRow(int $index): void
    {
        if (count($this->documents) === 1) {
            $this->addError(
                'documents',
                'The request must contain at least one document.'
            );

            return;
        }

        unset($this->documents[$index]);

        $this->documents = array_values($this->documents);

        $this->resetValidation('documents');
    }

    protected function rules(): array
    {
        return [
            'service_template_id' => [
                'required',
                Rule::exists('service_templates', 'id')
                    ->where(
                        fn ($query) => $query->where('is_active', true)
                    ),
            ],

            'customer_name' => [
                'required',
                'string',
                'max:255',
            ],

            'customer_mobile' => [
                'required',
                'string',
                'max:30',
            ],

            'customer_email' => [
                'nullable',
                'email',
                'max:255',
                'required_if:preferred_channel,email',
            ],

            'company_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'assigned_to' => [
                'nullable',
                Rule::exists('users', 'id')
                    ->where(
                        fn ($query) => $query->where('is_active', true)
                    ),
            ],

            'priority' => [
                'required',
                Rule::in(DocumentRequest::PRIORITIES),
            ],

            'preferred_channel' => [
                'required',
                Rule::in([
                    'whatsapp',
                    'email',
                    'copy_link',
                ]),
            ],

            'due_date' => [
                'nullable',
                'date',
                'after_or_equal:today',
            ],

            'next_action' => [
                'nullable',
                'string',
                'max:255',
            ],

            'customer_instructions' => [
                'nullable',
                'string',
                'max:5000',
            ],

            'internal_notes' => [
                'nullable',
                'string',
                'max:5000',
            ],

            'processing_days' => [
                'nullable',
                'integer',
                'min:1',
                'max:3650',
            ],

            'link_expiry_days' => [
                'required',
                'integer',
                'min:1',
                'max:365',
            ],

            'allow_multiple_uploads' => [
                'boolean',
            ],

            'documents' => [
                'required',
                'array',
                'min:1',
            ],

            'documents.*.service_template_document_id' => [
                'nullable',
                'exists:service_template_documents,id',
            ],

            'documents.*.name' => [
                'required',
                'string',
                'max:255',
            ],

            'documents.*.description' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'documents.*.customer_instructions' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'documents.*.is_required' => [
                'boolean',
            ],
        ];
    }

    protected function messages(): array
    {
        return [
            'customer_email.required_if' =>
                'Customer email is required when Email is selected.',

            'documents.required' =>
                'Add at least one requested document.',

            'documents.min' =>
                'Add at least one requested document.',

            'documents.*.name.required' =>
                'Every document row must have a document name.',
        ];
    }

    public function saveDraft(): void
    {
        $this->persistRequest(false);
    }

    public function createAndPrepareLink(): void
    {
        $this->persistRequest(true);
    }

    /*
     * Create the request, its copied checklist, activity record and
     * optional secure upload link inside one database transaction.
     * If any operation fails, Laravel rolls back the entire process.
     */
    private function persistRequest(bool $prepareUploadLink): void
    {
        $validated = $this->validate();

        $documentRequest = DB::transaction(
            function () use (
                $validated,
                $prepareUploadLink
            ): DocumentRequest {
                $documentRequest = DocumentRequest::create([
                    'request_number' =>
                        $this->generateRequestNumber(),

                    'service_template_id' =>
                        (int) $validated['service_template_id'],

                    'customer_name' =>
                        trim($validated['customer_name']),

                    'customer_mobile' =>
                        trim($validated['customer_mobile']),

                    'customer_email' =>
                        filled($validated['customer_email'])
                            ? strtolower(
                                trim($validated['customer_email'])
                            )
                            : null,

                    'company_name' =>
                        filled($validated['company_name'])
                            ? trim($validated['company_name'])
                            : null,

                    'assigned_to' =>
                        filled($validated['assigned_to'])
                            ? (int) $validated['assigned_to']
                            : null,

                    'created_by' => auth()->id(),

                    /*
                     * Preparing a link does not mean it has already
                     * been sent to the customer.
                     */
                    'status' => 'draft',

                    'priority' => $validated['priority'],

                    'preferred_channel' =>
                        $validated['preferred_channel'],

                    'due_date' =>
                        filled($validated['due_date'])
                            ? $validated['due_date']
                            : null,

                    'next_action' =>
                        filled($validated['next_action'])
                            ? trim($validated['next_action'])
                            : null,

                    'customer_instructions' =>
                        filled($validated['customer_instructions'])
                            ? trim(
                                $validated['customer_instructions']
                            )
                            : null,

                    'internal_notes' =>
                        filled($validated['internal_notes'])
                            ? trim($validated['internal_notes'])
                            : null,

                    'processing_days' =>
                        filled($validated['processing_days'])
                            ? (int) $validated['processing_days']
                            : null,

                    'allow_multiple_uploads' =>
                        $validated['allow_multiple_uploads'],
                ]);

                foreach (
                    $validated['documents']
                    as $index => $document
                ) {
                    $documentRequest->items()->create([
                        'service_template_document_id' =>
                            filled(
                                $document[
                                    'service_template_document_id'
                                ] ?? null
                            )
                                ? (int) $document[
                                    'service_template_document_id'
                                ]
                                : null,

                        'name' => trim($document['name']),

                        'description' =>
                            filled($document['description'] ?? null)
                                ? trim($document['description'])
                                : null,

                        'customer_instructions' =>
                            filled(
                                $document[
                                    'customer_instructions'
                                ] ?? null
                            )
                                ? trim(
                                    $document[
                                        'customer_instructions'
                                    ]
                                )
                                : null,

                        'is_required' =>
                            (bool) $document['is_required'],

                        'sort_order' => $index + 1,

                        'status' => 'pending',
                    ]);
                }

                /*
                 * Store an audit trail entry for the request creation.
                 */
                $documentRequest->activities()->create([
                    'actor_id' => auth()->id(),
                    'actor_type' => 'staff',
                    'activity_type' => 'request_created',
                    'description' =>
                        'Document request created as a draft.',
                    'to_status' => 'draft',
                    'occurred_at' => now(),
                ]);

                if ($prepareUploadLink) {
                    /*
                     * The customer receives the random raw token.
                     * Public lookup will use its SHA-256 hash.
                     */
                    $rawToken = Str::random(64);

                    $uploadLink = $documentRequest
                        ->uploadLinks()
                        ->create([
                            'token' => $rawToken,

                            'token_hash' =>
                                hash('sha256', $rawToken),

                            'expires_at' => now()->addDays(
                                (int) $validated[
                                    'link_expiry_days'
                                ]
                            ),

                            'allow_multiple_uploads' =>
                                $validated[
                                    'allow_multiple_uploads'
                                ],

                            'max_uploads' => null,
                            'upload_count' => 0,
                            'access_count' => 0,
                            'created_by' => auth()->id(),
                        ]);

                    $recipientAddress = match (
                        $validated['preferred_channel']
                    ) {
                        'email' =>
                            $validated['customer_email'],

                        default =>
                            $validated['customer_mobile'],
                    };

                    /*
                     * Communication remains prepared until WhatsApp,
                     * email or copy-link action is actually completed.
                     */
                    $documentRequest
                        ->communications()
                        ->create([
                            'upload_link_id' =>
                                $uploadLink->id,

                            'initiated_by' => auth()->id(),

                            'channel' =>
                                $validated[
                                    'preferred_channel'
                                ],

                            'direction' => 'outbound',

                            'recipient_name' =>
                                $validated['customer_name'],

                            'recipient_address' =>
                                $recipientAddress,

                            'subject' =>
                                'Documents required for '.
                                $documentRequest
                                    ->serviceTemplate
                                    ->name,

                            'message_body' =>
                                'Secure document upload request prepared.',

                            'status' => 'prepared',

                            'provider' => 'manual',
                        ]);

                    $documentRequest->activities()->create([
                        'actor_id' => auth()->id(),
                        'actor_type' => 'staff',

                        'activity_type' =>
                            'upload_link_created',

                        'description' =>
                            'Secure upload link prepared for '.
                            Str::headline(
                                $validated[
                                    'preferred_channel'
                                ]
                            ).'.',

                        'subject_type' =>
                            $uploadLink::class,

                        'subject_id' =>
                            $uploadLink->id,

                        'occurred_at' => now(),
                    ]);
                }

                return $documentRequest;
            },
            3
        );

        $message = $prepareUploadLink
            ? 'Request '.$documentRequest->request_number.
                ' created and its secure upload link is ready.'
            : 'Request '.$documentRequest->request_number.
                ' saved as a draft.';

        $this->resetCreateForm();

        session()->flash('status', $message);

        $this->resetPage();
    }

    public function cancelCreate(): void
    {
        $this->resetCreateForm();
    }

    private function resetCreateForm(): void
    {
        $this->resetValidation();

        $this->service_template_id = '';
        $this->customer_name = '';
        $this->customer_mobile = '';
        $this->customer_email = '';
        $this->company_name = '';
        $this->assigned_to = (string) auth()->id();
        $this->priority = 'normal';
        $this->preferred_channel = 'whatsapp';
        $this->due_date = '';
        $this->next_action = '';
        $this->customer_instructions = '';
        $this->internal_notes = '';
        $this->processing_days = '';
        $this->link_expiry_days = '7';
        $this->allow_multiple_uploads = true;
        $this->documents = [];
        $this->showCreateForm = false;
    }

    private function emptyDocumentRow(): array
    {
        return [
            'service_template_document_id' => null,
            'name' => '',
            'description' => '',
            'customer_instructions' => '',
            'is_required' => true,
        ];
    }

    /*
     * Generate a readable but unique request reference.
     */
    private function generateRequestNumber(): string
    {
        do {
            $requestNumber =
                'REQ-'.
                now()->format('Ymd').
                '-'.
                Str::upper(
                    substr((string) Str::ulid(), -6)
                );
        } while (
            DocumentRequest::query()
                ->where('request_number', $requestNumber)
                ->exists()
        );

        return $requestNumber;
    }

    public function with(): array
    {
        $currentUser = auth()->user();

        /*
         * Admins, managers and reviewers can see all requests.
         * Employees only see requests assigned to or created by them.
         */
        $canViewAllRequests = in_array(
            $currentUser->role,
            [
                'admin',
                'manager',
                'reviewer',
            ],
            true
        );

        $requestsQuery = DocumentRequest::query()
            ->with([
                'serviceTemplate:id,name,code',
                'assignedUser:id,name,email',
            ])
            ->withCount('items')
            ->withCount([
                'uploadLinks as active_upload_links_count' =>
                    fn ($query) => $query
                        ->whereNull('invalidated_at')
                        ->where('expires_at', '>', now()),
            ])
            ->when(
                ! $canViewAllRequests,
                fn ($query) => $query->where(
                    function ($query) use ($currentUser): void {
                        $query
                            ->where(
                                'assigned_to',
                                $currentUser->id
                            )
                            ->orWhere(
                                'created_by',
                                $currentUser->id
                            );
                    }
                )
            )
            ->when(
                filled($this->search),
                function ($query): void {
                    $search = trim($this->search);

                    $query->where(
                        function ($query) use ($search): void {
                            $query
                                ->where(
                                    'request_number',
                                    'like',
                                    '%'.$search.'%'
                                )
                                ->orWhere(
                                    'customer_name',
                                    'like',
                                    '%'.$search.'%'
                                )
                                ->orWhere(
                                    'customer_mobile',
                                    'like',
                                    '%'.$search.'%'
                                )
                                ->orWhere(
                                    'customer_email',
                                    'like',
                                    '%'.$search.'%'
                                )
                                ->orWhere(
                                    'company_name',
                                    'like',
                                    '%'.$search.'%'
                                );
                        }
                    );
                }
            )
            ->when(
                filled($this->statusFilter),
                fn ($query) => $query->where(
                    'status',
                    $this->statusFilter
                )
            )
            ->latest();

        $staffQuery = User::query()
            ->where('is_active', true)
            ->orderBy('name');

        if (
            ! in_array(
                $currentUser->role,
                ['admin', 'manager'],
                true
            )
        ) {
            $staffQuery->whereKey($currentUser->id);
        }

        return [
            'serviceTemplates' => ServiceTemplate::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'code',
                ]),

            'staffUsers' => $staffQuery->get([
                'id',
                'name',
                'email',
            ]),

            'requests' => $requestsQuery->paginate(10),

            'statusOptions' => DocumentRequest::STATUSES,
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">
                Document Requests
            </h1>

            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Request documents from customers and manage the service workflow.
            </p>
        </div>

        @if ($showCreateForm)
            <button
                type="button"
                wire:click="cancelCreate"
                class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
            >
                Close Form
            </button>
        @else
            <button
                type="button"
                wire:click="openCreateForm"
                class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
            >
                New Document Request
            </button>
        @endif
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-900 dark:bg-green-950 dark:text-green-300">
            {{ session('status') }}
        </div>
    @endif

    @if ($showCreateForm)
        <form
            wire:submit="createAndPrepareLink"
            class="space-y-6"
        >
            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-5">
                    <h2 class="text-lg font-semibold">
                        Customer Details
                    </h2>

                    <p class="mt-1 text-sm text-zinc-500">
                        The customer will not receive an account. These details belong only to this request.
                    </p>
                </div>

                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label
                            for="customer_name"
                            class="mb-1 block text-sm font-medium"
                        >
                            Customer Name
                        </label>

                        <input
                            id="customer_name"
                            type="text"
                            wire:model="customer_name"
                            autocomplete="name"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                        >

                        @error('customer_name')
                            <p class="mt-1 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <div>
                        <label
                            for="company_name"
                            class="mb-1 block text-sm font-medium"
                        >
                            Company Name
                        </label>

                        <input
                            id="company_name"
                            type="text"
                            wire:model="company_name"
                            autocomplete="organization"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                        >

                        @error('company_name')
                            <p class="mt-1 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <div>
                        <label
                            for="customer_mobile"
                            class="mb-1 block text-sm font-medium"
                        >
                            Mobile Number
                        </label>

                        <input
                            id="customer_mobile"
                            type="tel"
                            wire:model="customer_mobile"
                            autocomplete="tel"
                            placeholder="+971 50 123 4567"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                        >

                        @error('customer_mobile')
                            <p class="mt-1 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <div>
                        <label
                            for="customer_email"
                            class="mb-1 block text-sm font-medium"
                        >
                            Email Address
                        </label>

                        <input
                            id="customer_email"
                            type="email"
                            wire:model="customer_email"
                            autocomplete="email"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                        >

                        @error('customer_email')
                            <p class="mt-1 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-5">
                    <h2 class="text-lg font-semibold">
                        Service and Assignment
                    </h2>
                </div>

                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label
                            for="service_template_id"
                            class="mb-1 block text-sm font-medium"
                        >
                            Service Required
                        </label>

                        <select
                            id="service_template_id"
                            wire:model.live="service_template_id"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                        >
                            <option value="">
                                Select a service
                            </option>

                            @foreach ($serviceTemplates as $serviceTemplate)
                                <option value="{{ $serviceTemplate->id }}">
                                    {{ $serviceTemplate->name }}
                                    ({{ $serviceTemplate->code }})
                                </option>
                            @endforeach
                        </select>

                        @error('service_template_id')
                            <p class="mt-1 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror

                        @if ($serviceTemplates->isEmpty())
                            <p class="mt-2 text-sm text-amber-600">
                                No active service templates are available.
                            </p>
                        @endif
                    </div>

                    <div>
                        <label
                            for="assigned_to"
                            class="mb-1 block text-sm font-medium"
                        >
                            Assigned Staff
                        </label>

                        <select
                            id="assigned_to"
                            wire:model="assigned_to"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                        >
                            <option value="">
                                Unassigned
                            </option>

                            @foreach ($staffUsers as $staffUser)
                                <option value="{{ $staffUser->id }}">
                                    {{ $staffUser->name }}
                                </option>
                            @endforeach
                        </select>

                        @error('assigned_to')
                            <p class="mt-1 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <div>
                        <label
                            for="priority"
                            class="mb-1 block text-sm font-medium"
                        >
                            Priority
                        </label>

                        <select
                            id="priority"
                            wire:model="priority"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                        >
                            <option value="low">Low</option>
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>

                        @error('priority')
                            <p class="mt-1 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <div>
                        <label
                            for="due_date"
                            class="mb-1 block text-sm font-medium"
                        >
                            Due Date
                        </label>

                        <input
                            id="due_date"
                            type="date"
                            wire:model="due_date"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                        >

                        @error('due_date')
                            <p class="mt-1 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <div>
                        <label
                            for="processing_days"
                            class="mb-1 block text-sm font-medium"
                        >
                            Processing Days
                        </label>

                        <input
                            id="processing_days"
                            type="number"
                            min="1"
                            max="3650"
                            wire:model="processing_days"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                        >

                        @error('processing_days')
                            <p class="mt-1 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <div>
                        <label
                            for="next_action"
                            class="mb-1 block text-sm font-medium"
                        >
                            Next Action
                        </label>

                        <input
                            id="next_action"
                            type="text"
                            wire:model="next_action"
                            placeholder="Example: Send request to customer"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                        >

                        @error('next_action')
                            <p class="mt-1 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-5">
                    <h2 class="text-lg font-semibold">
                        Customer Communication
                    </h2>

                    <p class="mt-1 text-sm text-zinc-500">
                        Choose how the secure upload request will be shared.
                    </p>
                </div>

                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label
                            for="preferred_channel"
                            class="mb-1 block text-sm font-medium"
                        >
                            Preferred Channel
                        </label>

                        <select
                            id="preferred_channel"
                            wire:model="preferred_channel"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                        >
                            <option value="whatsapp">
                                WhatsApp
                            </option>

                            <option value="email">
                                Email
                            </option>

                            <option value="copy_link">
                                Copy Secure Link
                            </option>
                        </select>

                        @error('preferred_channel')
                            <p class="mt-1 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <div>
                        <label
                            for="link_expiry_days"
                            class="mb-1 block text-sm font-medium"
                        >
                            Upload Link Expiry
                        </label>

                        <div class="flex items-center gap-3">
                            <input
                                id="link_expiry_days"
                                type="number"
                                min="1"
                                max="365"
                                wire:model="link_expiry_days"
                                class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                            >

                            <span class="text-sm text-zinc-500">
                                days
                            </span>
                        </div>

                        @error('link_expiry_days')
                            <p class="mt-1 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>
                </div>

                <label class="mt-5 flex items-center gap-3">
                    <input
                        type="checkbox"
                        wire:model="allow_multiple_uploads"
                        class="rounded border-zinc-300"
                    >

                    <span class="text-sm font-medium">
                        Allow the customer to upload more than once
                    </span>
                </label>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="grid gap-5">
                    <div>
                        <label
                            for="customer_instructions"
                            class="mb-1 block text-sm font-medium"
                        >
                            Customer Instructions
                        </label>

                        <textarea
                            id="customer_instructions"
                            wire:model="customer_instructions"
                            rows="4"
                            placeholder="Instructions shown on the secure customer upload page."
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                        ></textarea>

                        @error('customer_instructions')
                            <p class="mt-1 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <div>
                        <label
                            for="internal_notes"
                            class="mb-1 block text-sm font-medium"
                        >
                            Internal Staff Notes
                        </label>

                        <textarea
                            id="internal_notes"
                            wire:model="internal_notes"
                            rows="3"
                            placeholder="These notes are never shown to the customer."
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                        ></textarea>

                        @error('internal_notes')
                            <p class="mt-1 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold">
                            Requested Documents
                        </h2>

                        <p class="mt-1 text-sm text-zinc-500">
                            The checklist is copied from the selected service and can be adjusted for this request.
                        </p>
                    </div>

                    <button
                        type="button"
                        wire:click="addDocumentRow"
                        class="rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
                    >
                        Add Document
                    </button>
                </div>

                @error('documents')
                    <p class="mb-4 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror

                @if ($documents === [])
                    <div class="rounded-lg border border-dashed border-zinc-300 px-4 py-8 text-center text-sm text-zinc-500 dark:border-zinc-700">
                        Select a service template to load its document checklist.
                    </div>
                @else
                    <div class="space-y-4">
                        @foreach ($documents as $index => $document)
                            <div
                                wire:key="request-document-{{ $index }}"
                                class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-950"
                            >
                                <div class="grid gap-4 lg:grid-cols-[1fr_1.5fr_auto]">
                                    <div>
                                        <label
                                            for="request-document-name-{{ $index }}"
                                            class="mb-1 block text-sm font-medium"
                                        >
                                            Document Name
                                        </label>

                                        <input
                                            id="request-document-name-{{ $index }}"
                                            type="text"
                                            wire:model="documents.{{ $index }}.name"
                                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900"
                                        >

                                        @error("documents.$index.name")
                                            <p class="mt-1 text-sm text-red-600">
                                                {{ $message }}
                                            </p>
                                        @enderror
                                    </div>

                                    <div>
                                        <label
                                            for="request-document-instructions-{{ $index }}"
                                            class="mb-1 block text-sm font-medium"
                                        >
                                            Customer Instructions
                                        </label>

                                        <input
                                            id="request-document-instructions-{{ $index }}"
                                            type="text"
                                            wire:model="documents.{{ $index }}.customer_instructions"
                                            placeholder="Optional instructions"
                                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900"
                                        >

                                        @error("documents.$index.customer_instructions")
                                            <p class="mt-1 text-sm text-red-600">
                                                {{ $message }}
                                            </p>
                                        @enderror
                                    </div>

                                    <div class="flex items-end gap-4 pb-2">
                                        <label class="flex items-center gap-2">
                                            <input
                                                type="checkbox"
                                                wire:model="documents.{{ $index }}.is_required"
                                                class="rounded border-zinc-300"
                                            >

                                            <span class="text-sm font-medium">
                                                Required
                                            </span>
                                        </label>

                                        <button
                                            type="button"
                                            wire:click="removeDocumentRow({{ $index }})"
                                            class="text-sm font-medium text-red-600 hover:text-red-700"
                                        >
                                            Remove
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="flex flex-col-reverse gap-3 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm sm:flex-row sm:justify-end dark:border-zinc-700 dark:bg-zinc-900">
                <button
                    type="button"
                    wire:click="cancelCreate"
                    class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
                >
                    Cancel
                </button>

                <button
                    type="button"
                    wire:click="saveDraft"
                    wire:loading.attr="disabled"
                    wire:target="saveDraft"
                    class="rounded-lg border border-zinc-900 px-4 py-2 text-sm font-medium hover:bg-zinc-100 disabled:opacity-50 dark:border-zinc-300 dark:hover:bg-zinc-800"
                >
                    Save Draft
                </button>

                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="createAndPrepareLink"
                    class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
                >
                    <span
                        wire:loading.remove
                        wire:target="createAndPrepareLink"
                    >
                        Create and Prepare Link
                    </span>

                    <span
                        wire:loading
                        wire:target="createAndPrepareLink"
                    >
                        Creating...
                    </span>
                </button>
            </div>
        </form>
    @endif

    <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="mb-5 grid gap-3 md:grid-cols-[1fr_240px]">
            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Search request number, customer, mobile, or company..."
                class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
            >

            <select
                wire:model.live="statusFilter"
                class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
            >
                <option value="">
                    All Statuses
                </option>

                @foreach ($statusOptions as $statusOption)
                    <option value="{{ $statusOption }}">
                        {{ Str::headline($statusOption) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead>
                    <tr>
                        <th class="px-4 py-3 text-left text-sm font-semibold">
                            Request
                        </th>

                        <th class="px-4 py-3 text-left text-sm font-semibold">
                            Customer
                        </th>

                        <th class="px-4 py-3 text-left text-sm font-semibold">
                            Service
                        </th>

                        <th class="px-4 py-3 text-left text-sm font-semibold">
                            Channel
                        </th>

                        <th class="px-4 py-3 text-left text-sm font-semibold">
                            Status
                        </th>

                        <th class="px-4 py-3 text-left text-sm font-semibold">
                            Assigned
                        </th>

                        <th class="px-4 py-3 text-left text-sm font-semibold">
                            Due
                        </th>

                        <th class="px-4 py-3 text-left text-sm font-semibold">
                            Upload Link
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($requests as $documentRequest)
                        <tr wire:key="document-request-{{ $documentRequest->id }}">
                            <td class="px-4 py-3">
                                {{--
                                    The request number opens the complete
                                    request workspace using route-model binding.
                                --}}
                                <a
                                    href="{{ route(
                                        'document-requests.show',
                                        $documentRequest
                                    ) }}"
                                    wire:navigate
                                    class="font-medium text-blue-600 hover:text-blue-800 hover:underline dark:text-blue-400 dark:hover:text-blue-300"
                                >
                                    {{ $documentRequest->request_number }}
                                </a>

                                <div class="mt-1 text-xs text-zinc-500">
                                    {{ $documentRequest->items_count }}
                                    documents
                                </div>
                            </td>

                            <td class="px-4 py-3">
                                <div class="font-medium">
                                    {{ $documentRequest->customer_name }}
                                </div>

                                <div class="mt-1 text-sm text-zinc-500">
                                    {{ $documentRequest->customer_mobile }}
                                </div>
                            </td>

                            <td class="px-4 py-3">
                                <div>
                                    {{ $documentRequest->serviceTemplate->name }}
                                </div>

                                <div class="mt-1 text-xs text-zinc-500">
                                    {{ $documentRequest->serviceTemplate->code }}
                                </div>
                            </td>

                            <td class="px-4 py-3">
                                {{ Str::headline(
                                    $documentRequest->preferred_channel
                                ) }}
                            </td>

                            <td class="px-4 py-3">
                                <span class="rounded-full bg-zinc-100 px-2 py-1 text-xs font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                                    {{ Str::headline(
                                        $documentRequest->status
                                    ) }}
                                </span>
                            </td>

                            <td class="px-4 py-3">
                                {{ $documentRequest->assignedUser?->name
                                    ?? 'Unassigned' }}
                            </td>

                            <td class="px-4 py-3">
                                @if ($documentRequest->due_date)
                                    <span
                                        @class([
                                            'font-medium text-red-600' =>
                                                $documentRequest->isOverdue(),

                                            'text-zinc-600 dark:text-zinc-300' =>
                                                ! $documentRequest->isOverdue(),
                                        ])
                                    >
                                        {{ $documentRequest->due_date->format(
                                            'd M Y'
                                        ) }}
                                    </span>
                                @else
                                    —
                                @endif
                            </td>

                            <td class="px-4 py-3">
                                @if ($documentRequest->active_upload_links_count > 0)
                                    <span class="rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700 dark:bg-green-950 dark:text-green-300">
                                        Ready
                                    </span>
                                @else
                                    <span class="text-sm text-zinc-500">
                                        Not prepared
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td
                                colspan="8"
                                class="px-4 py-10 text-center text-zinc-500"
                            >
                                No document requests found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $requests->links() }}
        </div>
    </div>
</div>
