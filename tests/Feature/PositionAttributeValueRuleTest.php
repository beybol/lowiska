<?php

namespace Tests\Feature;

use App\Models\Position;
use App\Models\PositionAttribute;
use App\Models\PositionAttributeOption;
use App\Rules\PositionAttributeValueMatchesType;
use App\Services\PositionAttributeWriter;

/**
 * Reguła „wartość pasuje do typu cechy" oraz zapis rozkładający ją na kolumny.
 *
 * ⚠️ Reguła testowana jest BEZPOŚREDNIO, nie tylko przez formularz — ma jeden dom
 * w `app/Rules/` właśnie po to, żeby obowiązywała także akcję zbiorczą, import
 * i przyszłe API (ADR-011, `CLAUDE.md`).
 */
function validateAttributeValues(array $values): array
{
    $failures = [];

    (new PositionAttributeValueMatchesType)->validate(
        'position_attributes',
        $values,
        function (string $message) use (&$failures): void {
            $failures[] = $message;
        },
    );

    return $failures;
}

test('a value matching the attribute type passes', function () {
    $flag = PositionAttribute::factory()->create();
    $number = PositionAttribute::factory()->number('m')->create();
    $choice = PositionAttribute::factory()->choice()->create();
    $option = PositionAttributeOption::factory()->create(['position_attribute_id' => $choice->id]);

    $failures = validateAttributeValues([
        $flag->id => true,
        $number->id => '12.5',
        $choice->id => $option->id,
    ]);

    expect($failures)->toBe([]);
});

test('a text value for a numeric attribute is rejected', function () {
    $number = PositionAttribute::factory()->number('m')->create();

    expect(validateAttributeValues([$number->id => 'daleko']))->toHaveCount(1);
});

test('an option belonging to another attribute is rejected', function () {
    $choice = PositionAttribute::factory()->choice()->create();
    $otherChoice = PositionAttribute::factory()->choice()->create();
    $foreignOption = PositionAttributeOption::factory()->create([
        'position_attribute_id' => $otherChoice->id,
    ]);

    // ⚠️ Identyfikator opcji przychodzi z pola `Select`, czyli od klienta — bez tego
    // sprawdzenia podmiana stanu przypisałaby stanowisku opcję innej cechy.
    expect(validateAttributeValues([$choice->id => $foreignOption->id]))->toHaveCount(1);
});

test('an unknown attribute is rejected', function () {
    expect(validateAttributeValues([999999 => true]))->toHaveCount(1);
});

test('an empty value is allowed because it is the third state', function () {
    $flag = PositionAttribute::factory()->create();

    // „Nikt się nie wypowiedział" jest dozwolone i MUSI być odróżnialne od „nie".
    expect(validateAttributeValues([$flag->id => null]))->toBe([])
        ->and(validateAttributeValues([$flag->id => '']))->toBe([]);
});

test('each attribute type lands in its own column', function () {
    $position = Position::factory()->create();
    $flag = PositionAttribute::factory()->create();
    $number = PositionAttribute::factory()->number('m')->create();
    $choice = PositionAttribute::factory()->choice()->create();
    $option = PositionAttributeOption::factory()->create(['position_attribute_id' => $choice->id]);

    app(PositionAttributeWriter::class)->writeForPosition($position, [
        $flag->id => true,
        $number->id => '12.5',
        $choice->id => $option->id,
    ]);

    $values = $position->attributeValues()->get()->keyBy('position_attribute_id');

    expect($values[$flag->id]->value_flag)->toBeTrue()
        ->and($values[$flag->id]->value_number)->toBeNull()
        ->and($values[$number->id]->value_number)->toEqual('12.50')
        ->and($values[$number->id]->value_flag)->toBeNull()
        ->and($values[$choice->id]->position_attribute_option_id)->toBe($option->id)
        ->and($values[$choice->id]->value_flag)->toBeNull();
});

test('a missing row is distinguishable from a false value', function () {
    $position = Position::factory()->create();
    $answered = PositionAttribute::factory()->create();
    $unanswered = PositionAttribute::factory()->create();

    app(PositionAttributeWriter::class)->writeForPosition($position, [
        $answered->id => false,
    ]);

    $values = $position->attributeValues()->get()->keyBy('position_attribute_id');

    // ⚠️ To jest sedno „trzeciego stanu": cecha z odpowiedzią „nie" ma WIERSZ
    // z fałszem, a cecha bez odpowiedzi nie ma wiersza wcale. Gdyby brak wiersza
    // zapisywał się jako fałsz, filtr „bez pomostu" pokazywałby stanowiska,
    // o których nikt się nie wypowiedział.
    expect($values->has($answered->id))->toBeTrue()
        ->and($values[$answered->id]->value_flag)->toBeFalse()
        ->and($values->has($unanswered->id))->toBeFalse();
});

test('clearing a value removes the row instead of writing a false', function () {
    $position = Position::factory()->create();
    $flag = PositionAttribute::factory()->create();

    $writer = app(PositionAttributeWriter::class);
    $writer->writeForPosition($position, [$flag->id => true]);

    expect($position->attributeValues()->count())->toBe(1);

    $writer->writeForPosition($position, [$flag->id => null]);

    // Do trzeciego stanu trzeba móc WRÓCIĆ — inaczej raz wypełniona cecha zostaje
    // wypełniona na zawsze.
    expect($position->attributeValues()->count())->toBe(0);
});

test('writing the same attribute twice updates the row instead of duplicating it', function () {
    $position = Position::factory()->create();
    $number = PositionAttribute::factory()->number('m')->create();

    $writer = app(PositionAttributeWriter::class);
    $writer->writeForPosition($position, [$number->id => '10']);
    $writer->writeForPosition($position, [$number->id => '20']);

    expect($position->attributeValues()->count())->toBe(1)
        ->and($position->attributeValues()->first()->value_number)->toEqual('20.00');
});
