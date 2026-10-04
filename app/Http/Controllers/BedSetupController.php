<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminBedRequest;
use App\Http\Requests\AdminRoomRequest;
use App\Http\Requests\AdminWardRequest;
use App\Models\Bed;
use App\Models\Room;
use App\Models\Ward;
use App\Services\BedSetupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class BedSetupController extends Controller
{
    public function __construct(protected BedSetupService $setup)
    {
    }

    public function index(): View
    {
        $wards = Ward::query()->with('rooms.beds')->orderBy('name')->get();
        $wardOptions = Ward::query()->orderBy('name')->get();
        $roomOptions = Room::query()->with('ward')->orderBy('ward_id')->orderBy('number')->get();

        return view('admin.bed-setup.index', compact('wards', 'wardOptions', 'roomOptions'));
    }

    public function storeWard(AdminWardRequest $request): RedirectResponse
    {
        $this->setup->createWard($request->validated());

        return back()->with('success', 'Ward created.');
    }

    public function updateWard(AdminWardRequest $request, Ward $ward): RedirectResponse
    {
        $this->setup->updateWard($ward, $request->validated());

        return back()->with('success', 'Ward updated.');
    }

    public function destroyWard(Ward $ward): RedirectResponse
    {
        $this->setup->deleteWard($ward);

        return back()->with('success', 'Ward deleted.');
    }

    public function storeRoom(AdminRoomRequest $request): RedirectResponse
    {
        $this->setup->createRoom($request->validated());

        return back()->with('success', 'Room created.');
    }

    public function updateRoom(AdminRoomRequest $request, Room $room): RedirectResponse
    {
        $this->setup->updateRoom($room, $request->validated());

        return back()->with('success', 'Room updated.');
    }

    public function destroyRoom(Room $room): RedirectResponse
    {
        $this->setup->deleteRoom($room);

        return back()->with('success', 'Room deleted.');
    }

    public function storeBed(AdminBedRequest $request): RedirectResponse
    {
        $this->setup->createBed($request->validated());

        return back()->with('success', 'Bed created.');
    }

    public function updateBed(AdminBedRequest $request, Bed $bed): RedirectResponse
    {
        $this->setup->updateBed($bed, $request->validated());

        return back()->with('success', 'Bed updated.');
    }

    public function destroyBed(Bed $bed): RedirectResponse
    {
        $this->setup->deleteBed($bed);

        return back()->with('success', 'Bed deleted.');
    }
}
