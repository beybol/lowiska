@php
    use App\Filament\Resources\LongTermPermitResource;
    use App\Filament\Resources\AdditionalServiceResource;
    use App\Filament\Resources\PositionResource;
@endphp
<x-filament-panels::page>
    <div class="space-y-6">
        <div 
            x-data="{ activeTab: 'permits'}" 
            class="bg-white rounded-lg shadow"
        >
            <div class="border-b border-gray-200">
                <nav class="flex gap-4 px-6">
                    <x-tab-button 
                        tab-key="permits" 
                        :label="__('Long term permits')" 
                        :count="$longTermPermitsCount" 
                    />
                    <x-tab-button 
                        tab-key="services" 
                        :label="__('Additional services')" 
                        :count="$additionalServicesCount" 
                    />
                    <x-tab-button 
                        tab-key="positions" 
                        :label="__('Positions')" 
                        :count="$positionsCount" 
                    />
                </nav>
            </div>
            <div class="p-6">
                <div x-show="activeTab === 'permits'">
                    <x-management-tab 
                        :description="__('Manage long-term fishing permits for this fishery.')"
                        :create-url="$longTermPermitsCreateUrl"
                        :list-url="LongTermPermitResource::getUrl('index') . '?fishery=' . $fisheryId"
                        :count="$longTermPermitsCount"
                    />
                </div>
                <div x-show="activeTab === 'services'">
                    <x-management-tab 
                        :description="__('Manage additional services for this fishery.')"
                        :create-url="AdditionalServiceResource::getUrl('create', ['fishery' => $fisheryId])"
                        :list-url="AdditionalServiceResource::getUrl('index') . '?fishery=' . $fisheryId"
                        :count="$additionalServicesCount"
                    />
                </div>
                <div x-show="activeTab === 'positions'">
                    <x-management-tab 
                        :description="__('Manage positions for this fishery.')"
                        :create-url="PositionResource::getUrl('create', ['fishery' => $fisheryId])"
                        :list-url="PositionResource::getUrl('index') . '?fishery=' . $fisheryId"
                        :count="$positionsCount"
                    />
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
