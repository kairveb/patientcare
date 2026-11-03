<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminAssignUserRoleRequest;
use App\Http\Requests\AdminCreateUserRequest;
use App\Http\Requests\AdminSetUserStatusRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\UserAdministrationService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    private const MANAGED_ROLES = [
        'super-admin' => 'Super Admin',
        'patient' => 'Patient',
        'nurse' => 'Staff',
        'doctor' => 'Doctor',
        'registration' => 'Staff',
        'admin' => 'Admin',
    ];

    public function __construct(protected UserAdministrationService $users)
    {
    }

    public function index(): View
    {
        $query = User::query()->with(['roles', 'patient'])->orderBy('name');
        if (auth()->user()->hasRole('admin') && ! auth()->user()->hasRole('super-admin')) {
            $query->whereDoesntHave('roles', fn ($roles) => $roles->where('name', 'super-admin'));
        }

        return view('admin.users.index', [
            'users' => $query->paginate(20),
            'managedRoles' => $this->managedRoles(),
        ]);
    }

    public function create(): View
    {
        return view('admin.users.create', ['managedRoles' => $this->managedRoles()]);
    }

    public function store(AdminCreateUserRequest $request): RedirectResponse
    {
        $user = $this->users->create($request->validated(), $request->user());
        event(new Registered($user));

        return redirect()->route('admin.users.index')->with('success', 'User account created.');
    }

    public function assignRole(AdminAssignUserRoleRequest $request, User $user): RedirectResponse
    {
        $this->users->assignRole($user, $request->validated(), $request->user());

        return back()->with('success', 'User role updated.');
    }

    public function setStatus(AdminSetUserStatusRequest $request, User $user): RedirectResponse
    {
        $this->users->setActive($user, $request->boolean('is_active'), $request->user());

        return back()->with('success', $request->boolean('is_active') ? 'User account activated.' : 'User account deactivated.');
    }

    private function managedRoles(): array
    {
        if (auth()->user()->hasRole('admin') && ! auth()->user()->hasRole('super-admin')) {
            return array_diff_key(self::MANAGED_ROLES, ['super-admin' => true]);
        }

        return self::MANAGED_ROLES;
    }
}
