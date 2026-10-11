<?php

namespace App\Support;

/**
 * Kształt paska pobytu nad komórkami kalendarza (model połówek) — jeden dom dla kalendarza panelu
 * i kalendarza portalu, żeby oba rysowały dzień przyjazdu i wyjazdu tak samo.
 *
 * Pasek ma szerokość liczby dotkniętych dni. Ukos „/" w pierwszym i ostatnim dniu biegnie od lewego
 * dolnego rogu komórki do połowy jej górnej krawędzi: przyjazd zajmuje prawą część komórki, wyjazd —
 * lewy górny trójkąt. Krawędzie są równoległe, więc w dniu zmiany gości dwa paski stykają się na tej
 * samej linii. Obcięcie przez `clip-path` wyłącza też klikanie w odciętą część — wolna część dnia
 * trafia w komórkę pod spodem.
 */
final class CalendarBarShape
{
    /**
     * Wcięcia tekstu w ułamkach dnia — osobno dla linii górnej (wyżej, ukos szerszy) i dolnej,
     * dobrane tak, żeby ikony i litery nie wchodziły pod ukos.
     */
    private const INSET_TOP = ['start' => 0.5, 'end' => 0.8];

    private const INSET_BOTTOM = ['start' => 0.3, 'end' => 0.95];

    /**
     * @param  int  $days  liczba dni kalendarzowych, których pasek dotyka (z dniem wyjazdu)
     * @param  bool  $slantStart  ukos po lewej — `false`, gdy pasek zaczyna się przed oknem
     * @param  bool  $slantEnd  ukos po prawej — `false`, gdy pasek kończy się za oknem
     * @return array{style: string, top: string, bottom: string}
     */
    public static function css(int $days, bool $slantStart, bool $slantEnd): array
    {
        $days = max(1, $days);
        $cell = 100 / $days;
        $percent = fn (float $value): string => round($value, 4).'%';
        $topLeft = $slantStart ? $percent($cell / 2) : '0';
        $topRight = $slantEnd ? $percent(100 - $cell / 2) : '100%';
        $bottomRight = $slantEnd ? $percent(100 - $cell) : '100%';
        $inset = fn (bool $slant, float $share): string => $slant ? $percent($cell * $share) : '.5rem';

        return [
            'style' => 'width:calc('.($days * 100).'% + '.($days - 1).'px);clip-path:polygon('.$topLeft.' 0,'.$topRight.' 0,'.$bottomRight.' 100%,0 100%)',
            'top' => 'padding-left:'.$inset($slantStart, self::INSET_TOP['start']).';padding-right:'.$inset($slantEnd, self::INSET_TOP['end']),
            'bottom' => 'padding-left:'.$inset($slantStart, self::INSET_BOTTOM['start']).';padding-right:'.$inset($slantEnd, self::INSET_BOTTOM['end']),
        ];
    }
}
