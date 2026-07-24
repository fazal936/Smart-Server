<?php

use App\Models\DocumentRequest;
use Illuminate\Support\Str;
use Livewire\Component;

new class extends Component
{
    public DocumentRequest $documentRequest;

    public function mount(): void
    {
        $user = auth()->user();

        $canViewAllRequests = in_array(
            $user->role,
            [
                'admin',
                'manager',
                'reviewer',
            ],
            true
        );

        $ownsOrCreatedRequest =
            $this->documentRequest->assigned_to === $user->id
            || $this->documentRequest->created_by === $user->id;

        abort_unless(
            $canViewAllRequests || $ownsOrCreatedRequest,
            403,
            'You do not have permission to view this request.'
        );
    }

    public function with(): array
    {
        $requestRecord = DocumentRequest::query()
            ->with([
                'serviceTemplate:id,name,code,description',
                'assignedUser:id,name,email',
                'creator:id,name,email',

                'items' => fn ($query) => $query
                    ->orderBy('sort_order'),

                'items.uploadedDocuments' => fn ($query) => $query
                    ->where('is_current', true)
                    ->latest('uploaded_at'),

                'uploadLinks' => fn ($query) => $query
                    ->latest(),

                'communications' => fn ($query) => $query
                    ->latest()
                    ->limit(15),

                'communications.initiator:id,name,email',

                'activities' => fn ($query) => $query
                    ->orderByDesc('occurred_at')
                    ->limit(20),

                'activities.actor:id,name,email',
            ])
            ->findOrFail($this->documentRequest->id);

        $activeUploadLink = $requestRecord
            ->uploadLinks
            ->first(function ($uploadLink): bool {
                return $uploadLink->invalidated_at === null
                    && $uploadLink->expires_at->isFuture()
                    && ! $uploadLink->hasReachedUploadLimit();
            });

        $requiredDocuments = $requestRecord
            ->items
            ->where('is_required', true);

        $approvedRequiredDocuments = $requiredDocuments
            ->where('status', 'approved');

        $receivedDocuments = $requestRecord
            ->items
            ->filter(
                fn ($item) => in_array(
                    $item->status,
                    [
                        'uploaded',
                        'under_review',
                        'approved',
                        'rejected',
                        'replacement_required',
                    ],
                    true
                )
            );

        $completionPercentage = $requiredDocuments->count() > 0
            ? (int) round(
                (
                    $approvedRequiredDocuments->count()
                    / $requiredDocuments->count()
                ) * 100
            )
            : 0;

        return [
            'requestRecord' => $requestRecord,
            'activeUploadLink' => $activeUploadLink,
            'requiredDocumentsCount' => $requiredDocuments->count(),
            'approvedDocumentsCount' =>
                $approvedRequiredDocuments->count(),
            'receivedDocumentsCount' => $receivedDocuments->count(),
            'completionPercentage' => $completionPercentage,
        ];
    }
};
?>

<div class="space-y-6">
    @php
        $statusClasses = match ($requestRecord->status) {
            'completed',
            'service_approved' =>
                'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300',

            'documents_received',
            'pending_review' =>
                'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',

            'action_required',
            'additional_documents_required' =>
                'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',

            'ready_for_processing',
            'submitted_to_authority',
            'external_processing' =>
                'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',

            'cancelled',
            'expired' =>
                'bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',

            default =>
                'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
        };

        $priorityClasses = match ($requestRecord->priority) {
            'urgent' =>
                'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',

            'high' =>
                'bg-orange-100 text-orange-700 dark:bg-orange-950 dark:text-orange-300',

            'low' =>
                'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300',

            default =>
                'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
        };
    @endphp

    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <a
                href="{{ route('document-requests.index') }}"
                wire:navigate
                class="mb-3 inline-flex items-center text-sm font-medium text-zinc-500 hover:text-zinc-900 dark:hover:text-white"
            >
                ← Back to Document Requests
            </a>

            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">
                    {{ $requestRecord->request_number }}
                </h1>

                <span
                    class="rounded-full px-2.5 py-1 text-xs font-medium {{ $statusClasses }}"
                >
                    {{ Str::headline($requestRecord->status) }}
                </span>

                <span
                    class="rounded-full px-2.5 py-1 text-xs font-medium {{ $priorityClasses }}"
                >
                    {{ Str::headline($requestRecord->priority) }} Priority
                </span>
            </div>

            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                {{ $requestRecord->serviceTemplate->name }}
                for
                {{ $requestRecord->customer_name }}
            </p>
        </div>

        <div class="flex flex-wrap gap-3">
            <button
                type="button"
                disabled
                class="cursor-not-allowed rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-400 dark:border-zinc-700"
                title="Communication actions will be added in the next step."
            >
                Send Request
            </button>

            <button
                type="button"
                disabled
                class="cursor-not-allowed rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white opacity-50 dark:bg-white dark:text-zinc-900"
                title="Workflow actions will be added in the next step."
            >
                Update Status
            </button>
        </div>
    </div>

    @if ($requestRecord->isOverdue())
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
            This request is overdue. The due date was
            {{ $requestRecord->due_date->format('d M Y') }}.
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                Required Documents
            </p>

            <p class="mt-2 text-2xl font-semibold">
                {{ $requiredDocumentsCount }}
            </p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                Documents Received
            </p>

            <p class="mt-2 text-2xl font-semibold">
                {{ $receivedDocumentsCount }}
            </p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                Approved
            </p>

            <p class="mt-2 text-2xl font-semibold">
                {{ $approvedDocumentsCount }}
            </p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                Completion
            </p>

            <p class="mt-2 text-2xl font-semibold">
                {{ $completionPercentage }}%
            </p>

            <div class="mt-3 h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                <div
                    class="h-full rounded-full bg-zinc-900 dark:bg-white"
                    style="width: {{ $completionPercentage }}%"
                ></div>
            </div>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-5">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                        Customer and Service
                    </h2>
                </div>

                <dl class="grid gap-x-6 gap-y-5 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">
                            Customer Name
                        </dt>

                        <dd class="mt-1 font-medium">
                            {{ $requestRecord->customer_name }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">
                            Company
                        </dt>

                        <dd class="mt-1 font-medium">
                            {{ $requestRecord->company_name ?: '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">
                            Mobile
                        </dt>

                        <dd class="mt-1 font-medium">
                            {{ $requestRecord->customer_mobile }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">
                            Email
                        </dt>

                        <dd class="mt-1 font-medium">
                            {{ $requestRecord->customer_email ?: '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">
                            Service
                        </dt>

                        <dd class="mt-1 font-medium">
                            {{ $requestRecord->serviceTemplate->name }}
                        </dd>

                        <p class="mt-1 text-xs text-zinc-500">
                            {{ $requestRecord->serviceTemplate->code }}
                        </p>
                    </div>

                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">
                            Preferred Channel
                        </dt>

                        <dd class="mt-1 font-medium">
                            {{ Str::headline(
                                $requestRecord->preferred_channel
                            ) }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-5 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                            Requested Documents
                        </h2>

                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Documents requested from the customer for this service.
                        </p>
                    </div>

                    <span class="text-sm text-zinc-500">
                        {{ $requestRecord->items->count() }} items
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead>
                            <tr>
                                <th class="px-3 py-3 text-left text-sm font-semibold">
                                    Document
                                </th>

                                <th class="px-3 py-3 text-left text-sm font-semibold">
                                    Requirement
                                </th>

                                <th class="px-3 py-3 text-left text-sm font-semibold">
                                    Uploads
                                </th>

                                <th class="px-3 py-3 text-left text-sm font-semibold">
                                    Status
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($requestRecord->items as $item)
                                @php
                                    $itemStatusClasses = match ($item->status) {
                                        'approved' =>
                                            'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300',

                                        'rejected',
                                        'replacement_required' =>
                                            'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',

                                        'uploaded',
                                        'under_review' =>
                                            'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',

                                        default =>
                                            'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
                                    };
                                @endphp

                                <tr wire:key="request-item-{{ $item->id }}">
                                    <td class="px-3 py-4">
                                        <div class="font-medium">
                                            {{ $item->name }}
                                        </div>

                                        @if ($item->customer_instructions)
                                            <p class="mt-1 max-w-md text-sm text-zinc-500">
                                                {{ $item->customer_instructions }}
                                            </p>
                                        @endif
                                    </td>

                                    <td class="px-3 py-4">
                                        @if ($item->is_required)
                                            <span class="text-sm font-medium">
                                                Required
                                            </span>
                                        @else
                                            <span class="text-sm text-zinc-500">
                                                Optional
                                            </span>
                                        @endif
                                    </td>

                                    <td class="px-3 py-4">
                                        {{ $item->uploadedDocuments->count() }}
                                    </td>

                                    <td class="px-3 py-4">
                                        <span
                                            class="rounded-full px-2 py-1 text-xs font-medium {{ $itemStatusClasses }}"
                                        >
                                            {{ Str::headline($item->status) }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td
                                        colspan="4"
                                        class="px-3 py-8 text-center text-zinc-500"
                                    >
                                        No documents are attached to this request.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-5">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                        Instructions and Notes
                    </h2>
                </div>

                <div class="space-y-5">
                    <div>
                        <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            Customer Instructions
                        </h3>

                        <div class="mt-2 rounded-lg bg-zinc-50 p-4 text-sm text-zinc-700 dark:bg-zinc-950 dark:text-zinc-300">
                            {!! nl2br(
                                e(
                                    $requestRecord->customer_instructions
                                    ?: 'No customer instructions were added.'
                                )
                            ) !!}
                        </div>
                    </div>

                    <div>
                        <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            Internal Staff Notes
                        </h3>

                        <div class="mt-2 rounded-lg bg-zinc-50 p-4 text-sm text-zinc-700 dark:bg-zinc-950 dark:text-zinc-300">
                            {!! nl2br(
                                e(
                                    $requestRecord->internal_notes
                                    ?: 'No internal notes were added.'
                                )
                            ) !!}
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-5">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                        Communication History
                    </h2>

                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        WhatsApp, email and copied-link activity for this request.
                    </p>
                </div>

                <div class="space-y-3">
                    @forelse ($requestRecord->communications as $communication)
                        <div
                            wire:key="communication-{{ $communication->id }}"
                            class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 sm:flex-row sm:items-start sm:justify-between dark:border-zinc-700"
                        >
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium">
                                        {{ Str::headline(
                                            $communication->channel
                                        ) }}
                                    </span>

                                    <span class="rounded-full bg-zinc-100 px-2 py-1 text-xs font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ Str::headline(
                                            $communication->status
                                        ) }}
                                    </span>
                                </div>

                                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                                    To:
                                    {{ $communication->recipient_name ?: 'Customer' }}
                                    —
                                    {{ $communication->recipient_address }}
                                </p>

                                @if ($communication->subject)
                                    <p class="mt-1 text-sm text-zinc-500">
                                        {{ $communication->subject }}
                                    </p>
                                @endif
                            </div>

                            <div class="text-sm text-zinc-500">
                                {{ $communication->created_at->format(
                                    'd M Y, H:i'
                                ) }}
                            </div>
                        </div>
                    @empty
                        <div class="rounded-lg border border-dashed border-zinc-300 px-4 py-8 text-center text-sm text-zinc-500 dark:border-zinc-700">
                            No communication has been recorded yet.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    Secure Upload Link
                </h2>

                @if ($activeUploadLink)
                    <div class="mt-4">
                        <span class="rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700 dark:bg-green-950 dark:text-green-300">
                            Active
                        </span>

                        <dl class="mt-5 space-y-4">
                            <div>
                                <dt class="text-sm text-zinc-500">
                                    Expires
                                </dt>

                                <dd class="mt-1 font-medium">
                                    {{ $activeUploadLink->expires_at->format(
                                        'd M Y, H:i'
                                    ) }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-sm text-zinc-500">
                                    Access Count
                                </dt>

                                <dd class="mt-1 font-medium">
                                    {{ $activeUploadLink->access_count }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-sm text-zinc-500">
                                    Upload Count
                                </dt>

                                <dd class="mt-1 font-medium">
                                    {{ $activeUploadLink->upload_count }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-sm text-zinc-500">
                                    Multiple Uploads
                                </dt>

                                <dd class="mt-1 font-medium">
                                    {{ $activeUploadLink->allow_multiple_uploads
                                        ? 'Allowed'
                                        : 'Not allowed' }}
                                </dd>
                            </div>
                        </dl>

                        <div class="mt-5 rounded-lg bg-zinc-50 p-3 text-sm text-zinc-500 dark:bg-zinc-950">
                            Copy, WhatsApp and email actions will be connected after the public upload page is created.
                        </div>
                    </div>
                @else
                    <div class="mt-4 rounded-lg border border-dashed border-zinc-300 px-4 py-6 text-center dark:border-zinc-700">
                        <p class="text-sm text-zinc-500">
                            No usable upload link is currently available.
                        </p>

                        <button
                            type="button"
                            disabled
                            class="mt-4 cursor-not-allowed rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white opacity-50 dark:bg-white dark:text-zinc-900"
                        >
                            Generate Secure Link
                        </button>
                    </div>
                @endif
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    Assignment and Timing
                </h2>

                <dl class="mt-5 space-y-4">
                    <div>
                        <dt class="text-sm text-zinc-500">
                            Assigned Staff
                        </dt>

                        <dd class="mt-1 font-medium">
                            {{ $requestRecord->assignedUser?->name
                                ?? 'Unassigned' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm text-zinc-500">
                            Created By
                        </dt>

                        <dd class="mt-1 font-medium">
                            {{ $requestRecord->creator?->name
                                ?? 'Unknown' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm text-zinc-500">
                            Created
                        </dt>

                        <dd class="mt-1 font-medium">
                            {{ $requestRecord->created_at->format(
                                'd M Y, H:i'
                            ) }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm text-zinc-500">
                            Due Date
                        </dt>

                        <dd class="mt-1 font-medium">
                            {{ $requestRecord->due_date?->format('d M Y')
                                ?? 'Not set' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm text-zinc-500">
                            Processing Time
                        </dt>

                        <dd class="mt-1 font-medium">
                            @if ($requestRecord->processing_days)
                                {{ $requestRecord->processing_days }}
                                days
                            @else
                                Not set
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm text-zinc-500">
                            Next Action
                        </dt>

                        <dd class="mt-1 font-medium">
                            {{ $requestRecord->next_action ?: 'Not set' }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    Activity Timeline
                </h2>

                <div class="mt-5 space-y-5">
                    @forelse ($requestRecord->activities as $activity)
                        <div
                            wire:key="activity-{{ $activity->id }}"
                            class="relative border-l border-zinc-200 pl-5 dark:border-zinc-700"
                        >
                            <span class="absolute -left-1.5 top-1.5 h-3 w-3 rounded-full bg-zinc-900 dark:bg-white"></span>

                            <div class="text-sm font-medium">
                                {{ Str::headline(
                                    $activity->activity_type
                                ) }}
                            </div>

                            @if ($activity->description)
                                <p class="mt-1 text-sm text-zinc-500">
                                    {{ $activity->description }}
                                </p>
                            @endif

                            @if (
                                $activity->from_status
                                || $activity->to_status
                            )
                                <p class="mt-1 text-xs text-zinc-500">
                                    @if ($activity->from_status)
                                        {{ Str::headline(
                                            $activity->from_status
                                        ) }}
                                    @endif

                                    @if (
                                        $activity->from_status
                                        && $activity->to_status
                                    )
                                        →
                                    @endif

                                    @if ($activity->to_status)
                                        {{ Str::headline(
                                            $activity->to_status
                                        ) }}
                                    @endif
                                </p>
                            @endif

                            <p class="mt-2 text-xs text-zinc-400">
                                {{ $activity->actor?->name
                                    ?? Str::headline(
                                        $activity->actor_type
                                    ) }}

                                ·

                                {{ $activity->occurred_at->format(
                                    'd M Y, H:i'
                                ) }}
                            </p>
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500">
                            No activity has been recorded.
                        </p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
