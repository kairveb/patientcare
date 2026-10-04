<?php

namespace App\Services;

use App\Models\Bed;
use App\Models\Room;
use App\Models\Ward;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BedSetupService
{
    public function __construct(protected AuditLogService $audit)
    {
    }

    public function createWard(array $data): Ward
    {
        return DB::transaction(function () use ($data): Ward {
            $ward = Ward::create([
                ...$data,
                'active' => $data['active'] ?? true,
            ]);
            $this->audit->log('CREATE_WARD', 'ward', $ward->id, 'SUCCESS', ['code' => $ward->code]);

            return $ward;
        });
    }

    public function updateWard(Ward $ward, array $data): Ward
    {
        return DB::transaction(function () use ($ward, $data): Ward {
            $lockedWard = Ward::query()->lockForUpdate()->findOrFail($ward->id);
            $lockedWard->update($data);
            $this->audit->log('UPDATE_WARD', 'ward', $lockedWard->id, 'SUCCESS', ['code' => $lockedWard->code]);

            return $lockedWard;
        });
    }

    public function deleteWard(Ward $ward): void
    {
        DB::transaction(function () use ($ward): void {
            $lockedWard = Ward::query()->lockForUpdate()->findOrFail($ward->id);
            if ($lockedWard->rooms()->exists()) {
                throw ValidationException::withMessages([
                    'ward' => ['Remove or move all rooms before deleting this ward.'],
                ]);
            }

            $wardId = $lockedWard->id;
            $code = $lockedWard->code;
            $lockedWard->delete();
            $this->audit->log('DELETE_WARD', 'ward', $wardId, 'SUCCESS', ['code' => $code]);
        });
    }

    public function createRoom(array $data): Room
    {
        return DB::transaction(function () use ($data): Room {
            $room = Room::create($data);
            $this->audit->log('CREATE_ROOM', 'room', $room->id, 'SUCCESS', [
                'ward_id' => $room->ward_id,
                'number' => $room->number,
            ]);

            return $room;
        });
    }

    public function updateRoom(Room $room, array $data): Room
    {
        return DB::transaction(function () use ($room, $data): Room {
            $lockedRoom = Room::query()->lockForUpdate()->findOrFail($room->id);
            if ((int) $data['ward_id'] !== (int) $lockedRoom->ward_id && $lockedRoom->beds()->exists()) {
                throw ValidationException::withMessages([
                    'ward_id' => ['Rooms containing beds cannot be moved to another ward.'],
                ]);
            }

            $lockedRoom->update($data);
            $this->audit->log('UPDATE_ROOM', 'room', $lockedRoom->id, 'SUCCESS', [
                'ward_id' => $lockedRoom->ward_id,
                'number' => $lockedRoom->number,
            ]);

            return $lockedRoom;
        });
    }

    public function deleteRoom(Room $room): void
    {
        DB::transaction(function () use ($room): void {
            $lockedRoom = Room::query()->lockForUpdate()->findOrFail($room->id);
            if ($lockedRoom->beds()->exists()) {
                throw ValidationException::withMessages([
                    'room' => ['Remove the beds before deleting this room.'],
                ]);
            }

            $roomId = $lockedRoom->id;
            $number = $lockedRoom->number;
            $lockedRoom->delete();
            $this->audit->log('DELETE_ROOM', 'room', $roomId, 'SUCCESS', ['number' => $number]);
        });
    }

    public function createBed(array $data): Bed
    {
        return DB::transaction(function () use ($data): Bed {
            $bed = Bed::create([
                ...$data,
                'status' => Bed::STATUS_AVAILABLE,
            ]);
            $this->audit->log('CREATE_BED', 'bed', $bed->id, 'SUCCESS', [
                'room_id' => $bed->room_id,
                'number' => $bed->number,
            ]);

            return $bed;
        });
    }

    public function updateBed(Bed $bed, array $data): Bed
    {
        return DB::transaction(function () use ($bed, $data): Bed {
            $lockedBed = Bed::query()->lockForUpdate()->findOrFail($bed->id);
            if ((int) $data['room_id'] !== (int) $lockedBed->room_id
                && ($lockedBed->status !== Bed::STATUS_AVAILABLE
                    || $lockedBed->assignments()->exists()
                    || $lockedBed->reservations()->exists())) {
                throw ValidationException::withMessages([
                    'room_id' => ['Beds with admission history cannot be moved to another room.'],
                ]);
            }

            $lockedBed->update($data);
            $this->audit->log('UPDATE_BED', 'bed', $lockedBed->id, 'SUCCESS', [
                'room_id' => $lockedBed->room_id,
                'number' => $lockedBed->number,
            ]);

            return $lockedBed;
        });
    }

    public function deleteBed(Bed $bed): void
    {
        DB::transaction(function () use ($bed): void {
            $lockedBed = Bed::query()->lockForUpdate()->findOrFail($bed->id);
            if ($lockedBed->status !== Bed::STATUS_AVAILABLE
                || $lockedBed->assignments()->exists()
                || $lockedBed->reservations()->exists()) {
                throw ValidationException::withMessages([
                    'bed' => ['Only available beds with no admission or reservation history can be deleted.'],
                ]);
            }

            $bedId = $lockedBed->id;
            $number = $lockedBed->number;
            $lockedBed->delete();
            $this->audit->log('DELETE_BED', 'bed', $bedId, 'SUCCESS', ['number' => $number]);
        });
    }
}
