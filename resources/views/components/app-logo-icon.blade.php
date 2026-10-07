{{-- The Ferrite cassette. Body in the text colour, tape in the theme accent (`--color-accent`). --}}
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 120" role="img" aria-hidden="true" {{ $attributes }}>
  <mask id="ferrite-front" maskUnits="userSpaceOnUse" x="0" y="0" width="128" height="120">
    <rect width="128" height="120" fill="#fff"/>
    <rect x="1" y="3" width="126" height="88" rx="19" fill="#000"/>
  </mask>
  <path mask="url(#ferrite-front)" d="M90 70 V90 C 90 102, 104 100, 106 111 L 106 118" fill="none" stroke="var(--color-accent, currentColor)" stroke-width="7" stroke-linecap="butt" stroke-linejoin="round"/>
  <g fill="none" stroke="currentColor" stroke-width="9" stroke-linecap="round" stroke-linejoin="round">
    <rect x="6" y="8" width="116" height="78" rx="14"/>
    <circle cx="42" cy="44" r="14"/>
    <circle cx="86" cy="44" r="14"/>
    <path d="M28 86 L36 68 H92 L100 86"/>
  </g>
  <circle cx="42" cy="44" r="4.5" fill="currentColor"/>
  <circle cx="86" cy="44" r="4.5" fill="currentColor"/>
</svg>
