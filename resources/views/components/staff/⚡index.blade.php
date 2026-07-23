<?php

use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'staffMembers' => User::query()
                ->when(
                    $this->search,
                    fn ($query) => $query->where(function ($query) {
                        $query->where('name', 'like', '%'.$this->search.'%')
                            ->orWhere('email', 'like', '%'.$this->search.'%')
                            ->orWhere('role', 'like', '%'.$this->search.'%');
                    })
                )
                ->orderBy('name')
                ->paginate(10),
        ];
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">
            Staff Management
        </h1>

        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
            View and manage SmartServe staff accounts.
        </p>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="mb-4">
            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Search staff..."
                class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
            >
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead>
                    <tr>
                        <th class="px-4 py-3 text-left text-sm font-semibold">Name</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold">Email</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold">Role</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold">Status</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($staffMembers as $staff)
                        <tr>
                            <td class="px-4 py-3">{{ $staff->name }}</td>
                            <td class="px-4 py-3">{{ $staff->email }}</td>
                            <td class="px-4 py-3 capitalize">{{ $staff->role }}</td>
                            <td class="px-4 py-3">
                                @if ($staff->is_active)
                                    <span class="rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700">
                                        Active
                                    </span>
                                @else
                                    <span class="rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-700">
                                        Inactive
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-zinc-500">
                                No staff members found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $staffMembers->links() }}
        </div>
    </div>
</div>
