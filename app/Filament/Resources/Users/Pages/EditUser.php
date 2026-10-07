<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\CallsServices;
use App\Filament\Resources\Users\UserResource;
use App\Services\Users\UserManagementService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    use CallsServices;

    protected static string $resource = UserResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->callService(fn () => app(UserManagementService::class)->update(auth()->user(), $record, $data));
    }
}
