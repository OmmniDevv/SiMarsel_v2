{{-- Data Master tab: one subtab per list, so a long list never stretches another. --}}
@php
    $masterTabs = ['funnel' => 'Funnel', 'source' => 'Source', 'platform' => 'Platform'];
@endphp

<div x-data="promoTabs('promo-master', @js(array_keys($masterTabs)))" class="mt-6 grid min-w-0 gap-4">
    <div class="min-w-0 sm:w-fit">
        @include('pages.promo.components.promo-subtabs', ['id' => 'promo-master', 'label' => 'Data Master', 'tabs' => $masterTabs])
    </div>

    <div id="panel-promo-master-funnel" role="tabpanel" aria-labelledby="tab-promo-master-funnel" tabindex="0" x-show="tab === 'funnel'" class="min-w-0 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary">
        @include('pages.promo.components.master-table', [
            'type' => 'funnel',
            'label' => 'Funnel',
            'description' => 'Tempat kode promo dibagikan.',
            'placeholder' => 'Webinar',
        ])
    </div>

    <div id="panel-promo-master-source" role="tabpanel" aria-labelledby="tab-promo-master-source" tabindex="0" x-show="tab === 'source'" x-cloak class="min-w-0 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary">
        @include('pages.promo.components.master-table', [
            'type' => 'source',
            'label' => 'Source',
            'description' => 'Sumber atau kanal asal kode promo.',
            'placeholder' => 'YouTube',
        ])
    </div>

    <div id="panel-promo-master-platform" role="tabpanel" aria-labelledby="tab-promo-master-platform" tabindex="0" x-show="tab === 'platform'" x-cloak class="min-w-0 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary">
        @include('pages.promo.components.platform-table')
    </div>
</div>
