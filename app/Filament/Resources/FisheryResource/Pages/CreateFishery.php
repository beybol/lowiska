<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Filament\Resources\FisheryResource;
use App\Helpers\Helper;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\View\View;

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

            return view('components.fishery-wizard-headers.fishery', [
                'wizard' => $this->wizard,
                'companies' => $companies,
            ]);
        }

        return parent::getHeader();
    }

    protected function getFormActions(): array
    {
        if ($this->wizard) {
            if (request()->query('verified_earlier', false)) {
                $url = 'filament.owner.resources.companies.create';
                $urlParameters = ['wizard' => 1];
            } else {
                $url = 'filament.owner.pages.verify-company';
                $urlParameters = ['company' => $this->companyId];
            }

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
                    ->url(route($url, $urlParameters)),
            ];
        }

        return [
            $this->getCreateFormAction(),
            $this->getCreateAnotherFormAction()
                ->label(__('Create and create another fishery')),
            $this->getCancelFormAction(),
        ];
    }

    public function getTitle(): string
    {
        return __('Create fishery');
    }
}
