<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Filament\Resources\FisheryResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use App\Helpers\Helper;
use Illuminate\Contracts\View\View;
use Filament\Actions\Action;

class CreateFishery extends CreateRecord
{
    protected static string $resource = FisheryResource::class;

    public bool $wizard = false;
    public ?int $companyId = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (Helper::isOwnerPanel()) {
            $data['user_id'] = auth()->id();
        }

        if ($this->wizard) {
            $data['company_id'] = $this->companyId;
        }

        return $data;
    }

    public function mount(): void
    {
        parent::mount();
        $this->wizard = request()->query('wizard', false);
        $this->companyId = request()->query('company', null);
    }

    public function getHeader(): ?View
    {
        if ($this->wizard) {
            $companies = auth()->user()->companies()->get();

            return view('components.fishery-wizard-fishery-header', [
                'wizard' => $this->wizard,
                'companies' => $companies,
            ]);
        }

        return parent::getHeader();
    }

    protected function getFormActions(): array
    {
        if ($this->wizard) {
            return [
                Action::make('createFishery')
                    ->label(__('Create fishery'))
                    ->color('success')
                    ->action(function () {
                        $this->create();
                    }),
                Action::make('cancel')
                    ->label(__('Previous'))
                    ->color('danger')
                    ->url(route('filament.owner.pages.verify-company', [
                        'company' => $this->companyId,
                    ])),
            ];
        }

        return parent::getFormActions();
    }
}
