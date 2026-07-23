<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $name = '';

    public string $email = '';

    public string $role = 'employee';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $is_active = true;

    public bool $showCreateForm = false;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'in:admin,manager,employee,reviewer,finance'],
            'password' => [
                'required',
                'confirmed',
                Password::min(10)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
            'is_active' => ['boolean'],
        ];
    }

    public function createStaff(): void
    {
        $validated = $this->validate();

        User::create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'is_active' => $validated['is_active'],
            'email_verified_at' => now(),
        ]);

        $this->reset([
            'name',
            'email',
            'password',
            'password_confirmation',
        ]);

        $this->role = 'employee';
        $this->is_active = true;
        $this->showCreateForm = false;

        session()->flash('status', 'Staff account created successfully.');

        $this->resetPage();
    }

    public function cancelCreate(): void
    {
        $this->resetValidation();

        $this->reset([
            'name',
            'email',
            'password',
            'password_confirmation',
        ]);

        $this->role = 'employee';
        $this->is_active = true;
        $this->showCreateForm = false;
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
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">
                Staff Management
            </h1>

            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Create and manage SmartServe staff accounts.
            </p>
        </div>

        <button
            type="button"
            wire:click="$toggle('showCreateForm')"
            class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
        >
            {{ $showCreateForm ? 'Close Form' : 'Create Staff' }}
        </button>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            {{ session('status') }}
        </div>
    @endif

    @if ($showCreateForm)
        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-5">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    Create Staff Account
                </h2>

                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Create an internal SmartServe account. Public registration remains disabled.
                </p>
            </div>

            <form wire:submit="createStaff" class="space-y-5">
                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label for="name" class="mb-1 block text-sm font-medium">
                            Full Name
                        </label>

                        <input
                            id="name"
                            type="text"
                            wire:model="name"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                        >

                        @error('name')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="email" class="mb-1 block text-sm font-medium">
                            Email Address
                        </label>

                        <input
                            id="email"
                            type="email"
                            wire:model="email"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                        >

                        @error('email')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="role" class="mb-1 block text-sm font-medium">
                            Role
                        </label>

                        <select
                            id="role"
                            wire:model="role"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                        >
                            <option value="employee">Employee</option>
                            <option value="manager">Manager</option>
                            <option value="reviewer">Reviewer</option>
                            <option value="finance">Finance</option>
                            <option value="admin">Admin</option>
                        </select>

                        @error('role')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex items-center pt-7">
                        <label class="flex items-center gap-3">
                            <input
                                type="checkbox"
                                wire:model="is_active"
                                class="rounded border-zinc-300"
                            >

                            <span class="text-sm font-medium">
                                Account is active
                            </span>
                        </label>
                    </div>

                    <div>
                        <label for="password" class="mb-1 block text-sm font-medium">
                            Temporary Password
                        </label>

                        <input
                            id="password"
                            type="password"
                            wire:model="password"
                            autocomplete="new-password"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                        >

                        @error('password')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="password_confirmation" class="mb-1 block text-sm font-medium">
                            Confirm Temporary Password
                        </label>

                        <input
                            id="password_confirmation"
                            type="password"
                            wire:model="password_confirmation"
                            autocomplete="new-password"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                        >
                    </div>
                </div>

                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    Password must contain at least 10 characters, uppercase and lowercase letters, a number, and a symbol.
                </p>

                <div class="flex justify-end gap-3">
                    <button
                        type="button"
                        wire:click="cancelCreate"
                        class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
                        wire:loading.attr="disabled"
                        wire:target="createStaff"
                    >
                        <span wire:loading.remove wire:target="createStaff">
                            Create Account
                        </span>

                        <span wire:loading wire:target="createStaff">
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
