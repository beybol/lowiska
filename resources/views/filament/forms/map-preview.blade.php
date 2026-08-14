@php
    $fullAddress = trim($address);
    $encodedAddress = urlencode($fullAddress);
@endphp

<div class="space-y-4">
    <div 
        class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700"
    >
        <div>
            <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100">
                {{ __('Map Preview') }}
            </h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                @if($fishery && $fishery->exists)
                    {{ __('Click to refresh map with current address') }}
                @else
                    {{ __('Fill address fields and click to show map.') }}
                @endif
            </p>
        </div>
        <button
            type="button"
            id="showMapButton"
            style="background-color: #2563eb !important; color: white !important;"
            class="inline-flex items-center gap-2 px-3 py-2 text-white text-sm font-medium rounded-lg transition-colors disabled:opacity-50"
        >
            <svg 
                class="w-4 h-4 mr-2" 
                fill="none" 
                stroke="currentColor" 
                viewBox="0 0 24 24"
            >
                <path 
                    stroke-linecap="round" 
                    stroke-linejoin="round" 
                    stroke-width="2" 
                    d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 013.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-1.447-.894L15 4m0 13V4m0 0L9 7"
                >
                </path>
            </svg>

            {{ __('Show Map') }}
        </button>
    </div>
    <div 
        id="mapContainer"
        class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden hidden"
    >
        <div class="relative">
            @if(config('services.google_maps.api_key'))
                <iframe 
                    id="mapIframe"
                    src="@if(!empty($fullAddress))https://www.google.com/maps/embed/v1/place?key={{ config('services.google_maps.api_key') }}&q={{ $encodedAddress }}@endif"
                    width="100%" 
                    height="350"
                    allowfullscreen="" 
                    loading="lazy" 
                    referrerpolicy="no-referrer-when-downgrade"
                    class="block border-0"
                >
                </iframe>
            @endif
            
            <div 
                id="mapPlaceholder"
                class="bg-gray-100 dark:bg-gray-800 h-[350px] flex items-center justify-center hidden">
                <div class="text-center">
                    <svg 
                        class="w-12 h-12 text-gray-400 mx-auto mb-3" 
                        fill="none" 
                        stroke="currentColor" 
                        viewBox="0 0 24 24"
                    >
                        <path 
                            stroke-linecap="round" 
                            stroke-linejoin="round" 
                            stroke-width="2" 
                            d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"
                        >
                        </path>
                        <path 
                            stroke-linecap="round" 
                            stroke-linejoin="round" 
                            stroke-width="2" 
                            d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"
                        ></path>
                    </svg>

                    <p 
                        class="text-sm text-gray-500 dark:text-gray-400 mb-2"
                    >
                        {{ __('Map preview not available') }}
                    </p>
                    <p class="text-xs text-gray-400 dark:text-gray-500">
                        {{ __('Click buttons below to view on Google Maps') }}
                    </p>
                </div>
            </div>
        </div>
        <div 
            class="bg-gray-50 dark:bg-gray-800 px-4 py-3 border-t border-gray-200 dark:border-gray-700"
        >
            <div class="flex justify-center space-x-3">
                <a 
                    id="fullMapLink"
                    href="@if(!empty($fullAddress))https://www.google.com/maps/search/?api=1&query={{ $encodedAddress }}@endif"
                    target="_blank"
                    class="inline-flex items-center px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors shadow-sm border-0"
                >
                    <svg 
                        class="w-4 h-4 mr-2" 
                        fill="currentColor" 
                        viewBox="0 0 20 20"
                    >
                        <path 
                            fill-rule="evenodd" 
                            d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" 
                            clip-rule="evenodd"
                        >
                        </path>
                    </svg>

                    <span class="text-white">
                        {{ __('View map in new tab') }}
                    </span>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
let mapLoadTimeout;

window.handleMapLoad = function(success) {
    clearTimeout(mapLoadTimeout);

    const iframe = document.getElementById('mapIframe');
    const placeholder = document.getElementById('mapPlaceholder');

    if (success && iframe && placeholder) {
        iframe.classList.remove('hidden');
        placeholder.classList.add('hidden');
    }
};

window.handleMapError = function() {
    clearTimeout(mapLoadTimeout);

    const iframe = document.getElementById('mapIframe');
    const placeholder = document.getElementById('mapPlaceholder');

    if (iframe && placeholder) {
        iframe.classList.add('hidden');
        placeholder.classList.remove('hidden');
    }
};

document.addEventListener('DOMContentLoaded', function() {
    const button = document.getElementById('showMapButton');

    if (button) {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            showMapFromForm();

            return false;
        });
    }

    const mapIframe = document.getElementById('mapIframe');

    if (mapIframe) {
        mapIframe.onload = function() {
            if (window.handleMapLoad) {
                window.handleMapLoad(true);
            }
        };
        mapIframe.onerror = function() {
            if (window.handleMapError) {
                window.handleMapError();
            }
        };
    }
});

function getFieldValue(fieldName) {
    const selectors = [
        `[name="${fieldName}"]`,
        `[name="data.${fieldName}"]`,
        `[wire\\:model="data.${fieldName}"]`,
        `[wire\\:model\\.defer="data.${fieldName}"]`,
        `[id*="${fieldName}"]`,
        `input[name*="${fieldName}"]`,
        `select[name*="${fieldName}"]`,
        `[name="mountedFormComponentsData[0][${fieldName}]"]`
    ];

    for (const selector of selectors) {
        const field = document.querySelector(selector);
        
        if (field && field.value) {
            return field.value;
        }
    }

    return '';
}

function getStateName() {
    const choicesItem = document.querySelector('.choices__item.choices__item--selectable[data-value][data-item]');
    
    if (choicesItem) {
        return choicesItem.textContent.replace(/Remove item.*/, '').trim();
    }

    const stateSelect = document.querySelector('select#data\\.state_id');

    if (stateSelect && stateSelect.selectedOptions?.[0]) {
        return stateSelect.selectedOptions[0].text;
    }

    return '';
}

function showMapFromForm() {
    const form = document.querySelector('form');

    if (!form) {
        alert('{{ __('Form not found') }}');
        return;
    }

    const street = getFieldValue('street');
    const buildingNumber = getFieldValue('building_number');
    const zipCode = getFieldValue('zip_code');
    const town = getFieldValue('town');
    const stateName = getStateName();
    const addressParts = [];
    const streetPart = (street + ' ' + buildingNumber).trim();
    const cityPart = (zipCode + ' ' + town).trim();

    if (streetPart) {
        addressParts.push(streetPart);
    }

    if (cityPart) {
        addressParts.push(cityPart);
    }

    if (stateName) {
        addressParts.push(stateName);
    }

    const fullAddress = addressParts.join(', ');

    if (!street.trim() 
        || !buildingNumber.trim() 
        || !zipCode.trim() 
        || !town.trim() 
        || !stateName.trim()
    ) {
        alert('{{ __('Please fill in all address fields first.') }}');

        return;
    }

    const mapContainer = document.getElementById('mapContainer');
    const displayAddress = document.getElementById('displayAddress');
    const mapIframe = document.getElementById('mapIframe');
    const mapPlaceholder = document.getElementById('mapPlaceholder');
    const fullMapLink = document.getElementById('fullMapLink');
    const directionsLink = document.getElementById('directionsLink');

    if (displayAddress) {
        displayAddress.textContent = fullAddress;
    }

    const encodedAddress = encodeURIComponent(fullAddress);

    if (mapIframe) {
        mapIframe.src = `https://www.google.com/maps/embed/v1/place?key={{ config('services.google_maps.api_key') }}&q=${encodedAddress}`;
        clearTimeout(mapLoadTimeout);
        mapIframe.style.display = 'block';

        if (mapPlaceholder) {
            mapPlaceholder.style.display = 'none';
        }

        mapLoadTimeout = setTimeout(() => {
            window.handleMapError();
        }, 10000);
    }

    if (fullMapLink) {
        fullMapLink.href = `https://www.google.com/maps/search/?api=1&query=${encodedAddress}`;
        fullMapLink.style.backgroundColor = '#2563eb';
        fullMapLink.style.color = 'white';
    }

    if (directionsLink) {
        directionsLink.href = `https://www.google.com/maps/dir/?api=1&destination=${encodedAddress}`;
        directionsLink.style.backgroundColor = '#16a34a';
        directionsLink.style.color = 'white';
    }

    if (mapContainer) {
        mapContainer.style.display = 'block';
    }
}
</script>
