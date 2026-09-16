@props(['name'=>'mail'])
@php
$paths = [
'mail'=>'M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm-1 1 9 7 9-7',
'compose'=>'M12 5H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-8M3 6l9 7 3-2M19 2v6m-3-3h6',
'inbox'=>'m4 4-2 11v5h20v-5L20 4H4ZM2 15h6l2 3h4l2-3h6',
'draft'=>'M14 2H5v20h14V7l-5-5ZM14 2v6h5M8 13h8m-8 4h5',
'send'=>'m22 2-8 20-4-8-8-4L22 2ZM10 14 22 2',
'archive'=>'M3 3h18v5H3zM5 8v13h14V8M10 12h4',
'trash'=>'M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7',
'search'=>'M21 21l-5-5M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z',
'refresh'=>'M20 7a9 9 0 1 0 1 9M20 2v6h-6',
'chevron'=>'m8 10 4 4 4-4',
'back'=>'m14 5-7 7 7 7',
'check'=>'m5 12 4 4L19 6',
'close'=>'m6 6 12 12M6 18 18 6',
'shield'=>'m12 2 8 3v6c0 5-8 11-8 11S4 16 4 11V5l8-3Zm-4 9 3 3 5-6',
'settings'=>'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8ZM12 2v3m0 14v3M2 12h3m14 0h3M5 5l2 2m10 10 2 2M5 19l2-2M17 7l2-2',
'moon'=>'M21 13A9 9 0 0 1 11 3a9 9 0 1 0 10 10Z',
'logout'=>'M9 3H3v18h6M10 12h12m-4-4 4 4-4 4',
'reply'=>'m9 5-7 7 7 7M2 12h13a6 6 0 0 1 6 6',
'paperclip'=>'m8 12 7-7a4 4 0 0 1 6 6L10 22a6 6 0 0 1-8-8L13 3m-7 12 9-9a1 1 0 0 1 2 2L8 17a2 2 0 0 1-3-3',
'building'=>'M4 22V2h12v20M16 10h4v12M8 6h4m-4 4h4m-4 4h4m-4 8v-4h4v4',
'menu'=>'M4 6h16M4 12h16M4 18h16',
];
@endphp
<svg {{ $attributes->class(['icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="{{ $paths[$name] ?? $paths['mail'] }}"/></svg>
