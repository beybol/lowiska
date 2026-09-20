# Źródła znaku

`hook.py` — geometria znaku (krzywe zagięcia, zwężenie ostrza, zadzior, wyśrodkowanie).
`build_logo.py` — generuje katalogi `svg/`, `png/`, `favicon/`.
`figures.py` — generuje `figury/` do księgi znaku.

Wymagania: Python 3.11+, `fonttools`, `Pillow`, headless Chromium (rasteryzacja SVG → PNG)
oraz font Fraunces 600 (paczka npm `@fontsource/fraunces`, plik `fraunces-latin-600-normal.woff`)
— potrzebny wyłącznie do logotypu słownego, w gotowych plikach litery są już krzywymi.

Ścieżki `ROOT`, `CHROME` i `FONT` na górze `build_logo.py` trzeba ustawić pod swoje środowisko.
Zmiana palety = podmiana stałych `BLUE`, `INK`, `ACC` i ponowne uruchomienie obu skryptów.
