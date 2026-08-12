<section class="rt-meters">
    <div class="rt-meters-head">
        <h3 class="rt-meters-title">Счётчики</h3>
        <p class="rt-meters-generated">Сформирована: {{ $generatedAt->format('d.m.Y H:i') }}</p>
    </div>

    <div class="rt-table-wrap">
        <table class="rt-table">
            <thead>
                <tr>
                    <th>№ счётчика</th>
                    <th class="rt-num">Предыдущее</th>
                    <th class="rt-num">Текущее</th>
                    <th class="rt-num">Расход</th>
                    <th class="rt-num">Тариф</th>
                    <th class="rt-num">Сумма</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($meterReadingLines as $line)
                    <tr>
                        <td>{{ $line['meter_number'] }}</td>
                        <td class="rt-num">{{ $line['previous_reading'] }}</td>
                        <td class="rt-num">{{ $line['current_reading'] }}</td>
                        <td class="rt-num">{{ $line['consumption'] }}</td>
                        <td class="rt-num">{{ $line['tariff_price'] }}</td>
                        <td class="rt-num rt-strong">{{ $line['amount'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="rt-empty" colspan="6">Нет показаний счётчиков</td>
                    </tr>
                @endforelse

                <tr class="rt-row-total">
                    <td colspan="3">Итого</td>
                    <td class="rt-num">{{ $volume }}</td>
                    <td></td>
                    <td class="rt-num">{{ $amount }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</section>
