<?php

use App\Models\ServiceTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public bool $showCreateForm = false;

    public string $name = '';

    public string $code = '';

    public string $description = '';

    public string $customer_instructions = '';

    public string $default_processing_days = '';

    public string $default_link_expiry_days = '7';

    public bool $allow_multiple_uploads = true;

    public bool $is_active = true;

    public array $documents = [];

    public function mount(): void
    {
        $this->resetDocumentRows();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    protected function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'code' => [
                'required',
                'string',
                'max:50',
                'alpha_dash',
                Rule::unique('service_templates', 'code'),
            ],

            'description' => [
                'nullable',
                'string',
                'max:5000',
            ],

            'customer_instructions' => [
                'nullable',
                'string',
                'max:5000',
            ],

            'default_processing_days' => [
                'nullable',
                'integer',
                'min:1',
                'max:3650',
            ],

            'default_link_expiry_days' => [
                'required',
                'integer',
                'min:1',
                'max:365',
            ],

            'allow_multiple_uploads' => [
                'boolean',
            ],

            'is_active' => [
                'boolean',
            ],

            'documents' => [
                'required',
                'array',
                'min:1',
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

            'documents.*.is_active' => [
                'boolean',
            ],
        ];
    }

    protected function messages(): array
    {
        return [
            'documents.required' => 'Add at least one required document.',
            'documents.min' => 'Add at least one required document.',
            'documents.*.name.required' => 'Each document row must have a document name.',
        ];
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
                'A service template must contain at least one document.'
            );

            return;
        }

        unset($this->documents[$index]);

        $this->documents = array_values($this->documents);

        $this->resetValidation('documents');
    }

    public function createServiceTemplate(): void
    {
        $validated = $this->validate();

        DB::transaction(function () use ($validated): void {
            $serviceTemplate = ServiceTemplate::create([
                'name' => trim($validated['name']),
                'code' => strtoupper(trim($validated['code'])),

                'description' => filled($validated['description'])
                    ? trim($validated['description'])
                    : null,

                'customer_instructions' => filled(
                    $validated['customer_instructions']
                )
                    ? trim($validated['customer_instructions'])
                    : null,

                'default_processing_days' => filled(
                    $validated['default_processing_days']
                )
                    ? (int) $validated['default_processing_days']
                    : null,

                'default_link_expiry_days' => (int) $validated[
                    'default_link_expiry_days'
                ],

                'allow_multiple_uploads' => $validated[
                    'allow_multiple_uploads'
                ],

                'is_active' => $validated['is_active'],

                'created_by' => auth()->id(),
            ]);

            foreach ($validated['documents'] as $index => $document) {
                $serviceTemplate->requiredDocuments()->create([
                    'name' => trim($document['name']),

                    'description' => filled($document['description'])
                        ? trim($document['description'])
                        : null,

                    'customer_instructions' => filled(
                        $document['customer_instructions']
                    )
                        ? trim($document['customer_instructions'])
                        : null,

                    'is_required' => (bool) $document['is_required'],

                    'sort_order' => $index + 1,

                    'is_active' => (bool) $document['is_active'],
                ]);
            }
        });

        $this->resetCreateForm();

        session()->flash(
            'status',
            'Service template created successfully.'
        );

        $this->resetPage();
    }

    public function cancelCreate(): void
    {
        $this->resetCreateForm();
    }

    private function resetCreateForm(): void
    {
        $this->resetValidation();

        $this->reset([
            'name',
            'code',
            'description',
            'customer_instructions',
            'default_processing_days',
        ]);

        $this->default_link_expiry_days = '7';
        $this->allow_multiple_uploads = true;
        $this->is_active = true;
        $this->showCreateForm = false;

        $this->resetDocumentRows();
    }

    private function resetDocumentRows(): void
    {
        $this->documents = [
            $this->emptyDocumentRow(),
        ];
    }

    private function emptyDocumentRow(): array
    {
        return [
            'name' => '',
            'description' => '',
            'customer_instructions' => '',
            'is_required' => true,
            'is_active' => true,
        ];
    }

    public function with(): array
    {
        return [
            'serviceTemplates' => ServiceTemplate::query()
                ->withCount([
                    'requiredDocuments as active_documents_count' => fn ($query) =>
                        $query->where('is_active', true),
                ])
                ->when(
                    filled($this->search),
                    function ($query): void {
                        $search = trim($this->search);

                        $query->where(function ($query) use ($search): void {
                            $query->where(
                                'name',
                                'like',
                                '%'.$search.'%'
                            )
                                ->orWhere(
                                    'code',
                                    'like',
                                    '%'.$search.'%'
                                )
                                ->orWhere(
                                    'description',
                                    'like',
                                    '%'.$search.'%'
                                );
                        });
                    }
                )
                ->orderBy('name')
                ->paginate(10),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">
                Service Templates
            </h1>

            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Configure services and their default document requirements.
            </p>
        </div>

        <button
            type="button"
            wire:click="$toggle('showCreateForm')"
            class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
        >
            {{ $showCreateForm ? 'Close Form' : 'Create Service' }}
        </button>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-900 dark:bg-green-950 dark:text-green-300">
            {{ session('status') }}
        </div>
    @endif

    @if ($showCreateForm)
        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-6">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    Create Service Template
                </h2>

                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    This template will be selected when staff creates a customer document request.
                </p>
            </div>

            <form wire:submit="createServiceTemplate" class="space-y-7">
                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label for="name" class="mb-1 block text-sm font-medium">
                            Service Name
                        </label>

                        <input
                            id="name"
                            type="text"
                            wire:model="name"
                            placeholder="Example: Trade Licence Renewal"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                        >

                        @error('name')
                            <p class="mt-1 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <div>
                        <label for="code" class="mb-1 block text-sm font-medium">
                            Service Code
                        </label>

                        <input
                            id="code"
                            type="text"
                            wire:model="code"
                            placeholder="Example: TL-RENEWAL"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 uppercase dark:border-zinc-700 dark:bg-zinc-950"
                        >

                        @error('code')
                            <p class="mt-1 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>
                </div>

                <div>
                    <label for="description" class="mb-1 block text-sm font-medium">
                        Internal Service Description
                    </label>

                    <textarea
                        id="description"
                        wire:model="description"
                        rows="3"
                        placeholder="Describe the service for SmartServe staff."
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                    ></textarea>

                    @error('description')
                        <p class="mt-1 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div>
                    <label
                        for="customer_instructions"
                        class="mb-1 block text-sm font-medium"
                    >
                        Default Customer Instructions
                    </label>

                    <textarea
                        id="customer_instructions"
                        wire:model="customer_instructions"
                        rows="4"
                        placeholder="Instructions shown to the customer on the secure upload page."
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                    ></textarea>

                    @error('customer_instructions')
                        <p class="mt-1 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label
                            for="default_processing_days"
                            class="mb-1 block text-sm font-medium"
                        >
                            Default Processing Days
                        </label>

                        <input
                            id="default_processing_days"
                            type="number"
                            min="1"
                            max="3650"
                            wire:model="default_processing_days"
                            placeholder="Example: 5"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                        >

                        @error('default_processing_days')
                            <p class="mt-1 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <div>
                        <label
                            for="default_link_expiry_days"
                            class="mb-1 block text-sm font-medium"
                        >
                            Upload Link Expiry Days
                        </label>

                        <input
                            id="default_link_expiry_days"
                            type="number"
                            min="1"
                            max="365"
                            wire:model="default_link_expiry_days"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                        >

                        @error('default_link_expiry_days')
                            <p class="mt-1 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:gap-8">
                    <label class="flex items-center gap-3">
                        <input
                            type="checkbox"
                            wire:model="allow_multiple_uploads"
                            class="rounded border-zinc-300"
                        >

                        <span class="text-sm font-medium">
                            Allow multiple customer uploads
                        </span>
                    </label>

                    <label class="flex items-center gap-3">
                        <input
                            type="checkbox"
                            wire:model="is_active"
                            class="rounded border-zinc-300"
                        >

                        <span class="text-sm font-medium">
                            Service is active
                        </span>
                    </label>
                </div>

                <div class="border-t border-zinc-200 pt-6 dark:border-zinc-700">
                    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-zinc-900 dark:text-white">
                                Required Documents
                            </h3>

                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                These documents will be added automatically when staff selects this service.
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

                    <div class="space-y-4">
                        @foreach ($documents as $index => $document)
                            <div
                                wire:key="service-document-{{ $index }}"
                                class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-950"
                            >
                                <div class="mb-4 flex items-center justify-between">
                                    <h4 class="font-medium text-zinc-900 dark:text-white">
                                        Document {{ $index + 1 }}
                                    </h4>

                                    <button
                                        type="button"
                                        wire:click="removeDocumentRow({{ $index }})"
                                        class="text-sm font-medium text-red-600 hover:text-red-700"
                                    >
                                        Remove
                                    </button>
                                </div>

                                <div class="grid gap-4 md:grid-cols-2">
                                    <div>
                                        <label
                                            for="document-name-{{ $index }}"
                                            class="mb-1 block text-sm font-medium"
                                        >
                                            Document Name
                                        </label>

                                        <input
                                            id="document-name-{{ $index }}"
                                            type="text"
                                            wire:model="documents.{{ $index }}.name"
                                            placeholder="Example: Passport Copy"
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
                                            for="document-description-{{ $index }}"
                                            class="mb-1 block text-sm font-medium"
                                        >
                                            Internal Description
                                        </label>

                                        <input
                                            id="document-description-{{ $index }}"
                                            type="text"
                                            wire:model="documents.{{ $index }}.description"
                                            placeholder="Optional internal description"
                                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900"
                                        >

                                        @error("documents.$index.description")
                                            <p class="mt-1 text-sm text-red-600">
                                                {{ $message }}
                                            </p>
                                        @enderror
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <label
                                        for="document-instructions-{{ $index }}"
                                        class="mb-1 block text-sm font-medium"
                                    >
                                        Customer Instructions
                                    </label>

                                    <textarea
                                        id="document-instructions-{{ $index }}"
                                        wire:model="documents.{{ $index }}.customer_instructions"
                                        rows="2"
                                        placeholder="Example: Upload a clear colour copy showing all four corners."
                                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900"
                                    ></textarea>

                                    @error("documents.$index.customer_instructions")
                                        <p class="mt-1 text-sm text-red-600">
                                            {{ $message }}
                                        </p>
                                    @enderror
                                </div>

                                <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:gap-8">
                                    <label class="flex items-center gap-3">
                                        <input
                                            type="checkbox"
                                            wire:model="documents.{{ $index }}.is_required"
                                            class="rounded border-zinc-300"
                                        >

                                        <span class="text-sm font-medium">
                                            Required document
                                        </span>
                                    </label>

                                    <label class="flex items-center gap-3">
                                        <input
                                            type="checkbox"
                                            wire:model="documents.{{ $index }}.is_active"
                                            class="rounded border-zinc-300"
                                        >

                                        <span class="text-sm font-medium">
                                            Active
                                        </span>
                                    </label>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="flex justify-end gap-3 border-t border-zinc-200 pt-5 dark:border-zinc-700">
                    <button
                        type="button"
                        wire:click="cancelCreate"
                        class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="createServiceTemplate"
                        class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
                    >
                        <span
                            wire:loading.remove
                            wire:target="createServiceTemplate"
                        >
                            Create Service
                        </span>

                        <span
                            wire:loading
                            wire:target="createServiceTemplate"
                        >
                            Creating...
                        </span>
                    </button>
                </div>
            </form>
        </div>
    @endif

    <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="mb-4">
            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Search by service name, code, or description..."
                class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
            >
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead>
                    <tr>
                        <th class="px-4 py-3 text-left text-sm font-semibold">
                            Service
                        </th>

                        <th class="px-4 py-3 text-left text-sm font-semibold">
                            Documents
                        </th>

                        <th class="px-4 py-3 text-left text-sm font-semibold">
                            Processing
                        </th>

                        <th class="px-4 py-3 text-left text-sm font-semibold">
                            Link Expiry
                        </th>

                        <th class="px-4 py-3 text-left text-sm font-semibold">
                            Status
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($serviceTemplates as $serviceTemplate)
                        <tr wire:key="service-template-{{ $serviceTemplate->id }}">
                            <td class="px-4 py-3">
                                <div class="font-medium">
                                    {{ $serviceTemplate->name }}
                                </div>

                                <div class="mt-1 text-sm text-zinc-500">
                                    {{ $serviceTemplate->code }}
                                </div>
                            </td>

                            <td class="px-4 py-3">
                                {{ $serviceTemplate->active_documents_count }}
                            </td>

                            <td class="px-4 py-3">
                                @if ($serviceTemplate->default_processing_days)
                                    {{ $serviceTemplate->default_processing_days }}
                                    days
                                @else
                                    —
                                @endif
                            </td>

                            <td class="px-4 py-3">
                                {{ $serviceTemplate->default_link_expiry_days }}
                                days
                            </td>

                            <td class="px-4 py-3">
                                @if ($serviceTemplate->is_active)
                                    <span class="rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700 dark:bg-green-950 dark:text-green-300">
                                        Active
                                    </span>
                                @else
                                    <span class="rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-700 dark:bg-red-950 dark:text-red-300">
                                        Inactive
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td
                                colspan="5"
                                class="px-4 py-8 text-center text-zinc-500"
                            >
                                No service templates found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $serviceTemplates->links() }}
        </div>
    </div>
</div>
