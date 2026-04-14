<?php

declare(strict_types=1);

namespace RhBlueprint\Settings\groups;

use RhBlueprint\Settings\GroupInterface;
use RhBlueprint\Settings\SettingField;

final class SupportGroup implements GroupInterface
{
    public function id(): string
    {
        return 'support_info';
    }

    public function tab(): string
    {
        return 'support';
    }

    public function title(): string
    {
        return __('Support-Informationen', 'rh-blueprint');
    }

    public function description(): string
    {
        return __('Kontaktdaten fuer Kunden die Hilfe brauchen. Werden im Dashboard-Widget und optional im Frontend angezeigt.', 'rh-blueprint');
    }

    public function fields(): array
    {
        return [
            new SettingField(
                id: 'name',
                type: SettingField::TYPE_TEXT,
                label: __('Name / Agentur', 'rh-blueprint'),
                description: __('Name des Ansprechpartners oder der Agentur.', 'rh-blueprint'),
                default: 'Robin Herbeck',
                keywords: ['kontakt', 'agentur', 'entwickler'],
            ),
            new SettingField(
                id: 'role',
                type: SettingField::TYPE_TEXT,
                label: __('Rolle', 'rh-blueprint'),
                description: __('Funktion oder Position des Ansprechpartners (z.B. "Webentwickler").', 'rh-blueprint'),
                default: 'Webentwickler',
                keywords: ['rolle', 'position', 'funktion', 'job'],
            ),
            new SettingField(
                id: 'email',
                type: SettingField::TYPE_EMAIL,
                label: __('Support-E-Mail', 'rh-blueprint'),
                description: __('Adresse fuer Supportanfragen.', 'rh-blueprint'),
                keywords: ['mail', 'kontakt', 'email'],
            ),
            new SettingField(
                id: 'calendar_url',
                type: SettingField::TYPE_URL,
                label: __('Termin-Kalender', 'rh-blueprint'),
                description: __('Link zu einem Buchungskalender (Cal.com, Calendly, etc.).', 'rh-blueprint'),
                keywords: ['termin', 'kalender', 'booking', 'cal', 'meeting'],
            ),
            new SettingField(
                id: 'website',
                type: SettingField::TYPE_URL,
                label: __('Webseite', 'rh-blueprint'),
                description: __('Link zur Agentur-Webseite oder zum Portfolio.', 'rh-blueprint'),
                keywords: ['website', 'url', 'homepage'],
            ),
            new SettingField(
                id: 'phone',
                type: SettingField::TYPE_TEXT,
                label: __('Telefon', 'rh-blueprint'),
                description: __('Telefonnummer fuer dringende Faelle (optional).', 'rh-blueprint'),
                keywords: ['telefon', 'nummer', 'phone'],
            ),
        ];
    }
}
