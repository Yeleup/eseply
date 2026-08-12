<?php

namespace App\Support;

/**
 * Дефолтный HTML-шаблон квитанции: используется печатью при отсутствии
 * сохранённого шаблона, редактором как стартовое содержимое и сбросом.
 */
final class ReceiptTemplateDefaults
{
    public static function html(): string
    {
        return (string) file_get_contents(resource_path('receipt-templates/default.html'));
    }

    public static function css(): string
    {
        return (string) file_get_contents(resource_path('receipt-templates/default.css'));
    }
}
