@props(['variant' => 'light', 'tagline' => true])
@php
    // light = navy wordmark for light surfaces; dark = off-white wordmark for navy surfaces.
    $ink = $variant === 'dark' ? '#F5F3EE' : '#161D2E';
@endphp
<svg {{ $attributes->merge(['class' => 'block']) }} xmlns="http://www.w3.org/2000/svg"
     viewBox="{{ $tagline ? '-30 -50 390 240' : '-6 -30 340 136' }}" role="img" aria-label="APQ Cleaning Service">
    <g fill="{{ $ink }}">
        <polygon points="0,100 36,0 64,0 100,100 71,100 50,38 29,100"/>
        <path fill-rule="evenodd" d="M110,0 H157 A30,30 0 0 1 157,62 H139 V100 H110 Z M139,24 H154 A7,7 0 0 1 154,38 H139 Z"/>
        <path fill-rule="evenodd" d="M240,0 A50,50 0 1 1 239.9,0 Z M240,25 A25,25 0 1 0 240.1,25 Z"/>
        <polygon points="246,64 271,64 300,100 274,100"/>
    </g>
    <path fill="#8E9683" d="M308,-22 Q311,-9 324,-6 Q311,-3 308,10 Q305,-3 292,-6 Q305,-9 308,-22 Z"/>
    @if ($tagline)
        <text x="150" y="160" text-anchor="middle" fill="{{ $ink }}" font-family="Montserrat, 'Helvetica Neue', Arial, sans-serif"
              font-size="24" font-weight="500" letter-spacing="7">CLEANING SERVICE</text>
    @endif
</svg>
