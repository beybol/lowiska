<?php

namespace App\Filament\Resources\LongTermPermitResource\Pages;

use App\Filament\Resources\LongTermPermitResource;
use App\Helpers\Helper;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use App\Models\Fishery;

class ListLongTermPermits extends ListRecords
{
    protected static string $resource = LongTermPermitResource::class;

    public function mount(): void
    {
        Helper::assertFisheryAccessOrAbort();
        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        $actions = [];
        
        if ($fisheryId = request()->get('fishery')) {
            $actions[] = Actions\CreateAction::make()
                ->url(fn () => static::$resource::getUrl('create', ['fishery' => $fisheryId]));
            $actions[] = Helper::getBackToFisheryManagementAction($fisheryId, 'action');
        } else {
            $actions[] = Actions\CreateAction::make();
        }
        
        return $actions;
    }

    public function getTitle(): string
    {
        if ($fisheryId = request()->get('fishery')) {
            $fishery = Fishery::find($fisheryId);
            
            if ($fishery) {
                return __('Long term permits for fishery') . ' ' . $fishery->name;
            }
        }
        
        return __('Long term permits');
    }

    protected function getTableQuery(): ?\Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getTableQuery();
        
        if ($fisheryId = request()->get('fishery')) {
            $query->where('fishery_id', $fisheryId);
        }
        
        return $query;
    }

    public function getBreadcrumbs(): array
    {
        $fisheryId = request()->get('fishery');

        return [
            LongTermPermitResource::getUrl('index', ['fishery' => $fisheryId]) 
                => __('Long term permits'),
            __('List'),
        ];
    }
}
