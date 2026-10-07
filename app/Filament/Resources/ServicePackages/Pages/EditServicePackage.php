<?php

namespace App\Filament\Resources\ServicePackages\Pages;

use App\Filament\Resources\ServicePackages\ServicePackageResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditServicePackage extends EditRecord
{
    protected static string $resource = ServicePackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Archive'),
            RestoreAction::make(),
        ];
    }
}
