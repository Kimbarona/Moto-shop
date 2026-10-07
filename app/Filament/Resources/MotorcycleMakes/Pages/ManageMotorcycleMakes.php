<?php

namespace App\Filament\Resources\MotorcycleMakes\Pages;

use App\Filament\Resources\MotorcycleMakes\MotorcycleMakeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMotorcycleMakes extends ManageRecords
{
    protected static string $resource = MotorcycleMakeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
