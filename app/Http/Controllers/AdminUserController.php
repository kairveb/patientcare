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
        'nurse' => 'Nurse',
        'doctor' => 'Doctor',
        'registration' => 'Registration Staff',
    ];

    public function __construct(protected UserAdministrationService $users)
    {
    }

    public function index(): View
    {
        $users = User::query()->with(['roles', 'patient'])->orderBy('name')->paginate(20);

        return view('admin.users.index', [
            'users' => $users,
            'managedRoles' => self::MANAGED_ROLES,
        ]);
    }

    public function create(): View
    {
        return view('admin.users.create', ['managedRoles' => self::MANAGED_ROLES]);
    }

    public function store(AdminCreateUserRequest $request): RedirectResponse
    {
        $user = $this->users->create($request->validated());
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
}
