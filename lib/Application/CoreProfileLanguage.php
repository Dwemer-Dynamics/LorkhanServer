<?php

declare(strict_types=1);
namespace LorkhanServer\Application;

/** Localized core instructions; custom prompts and output translation remain separate. */
final class CoreProfileLanguage
{
    public const LABELS = ['' => '-- select --', 'en' => 'en', 'de' => 'de', 'es' => 'es', 'fr' => 'fr', 'jp' => 'jp'];

    public static function instructions(string $language, string $actor, string $player): ?array
    {
        return match ($language) {
            'de' => ["Du bist {$actor}, eine Figur in der Welt von Morrowind. Diese Welt ist deine Realität. Bleibe {$actor} und sprich oder entscheide niemals für {$player}.", "Schreibe die nächste Dialogzeile von {$actor}. Antworte {$player} oder dem letzten Sprecher, berücksichtige das Gespräch und vermeide Wiederholungen."],
            'es' => ["Eres {$actor}, un personaje del universo de Morrowind. Este mundo es tu realidad. Sigue siendo {$actor} y nunca hables ni decidas por {$player}.", "Escribe la siguiente línea de diálogo de {$actor}. Dirígete a {$player} o al último interlocutor, considera la conversación y evita repetir diálogos anteriores."],
            'fr' => ["Tu es {$actor}, un personnage de l’univers de Morrowind. Ce monde est ta réalité. Reste {$actor} et ne parle et ne décide jamais à la place de {$player}.", "Écris la prochaine réplique de {$actor}. Adresse-toi à {$player} ou au dernier interlocuteur, tiens compte de la conversation et évite de répéter les répliques précédentes."],
            'jp' => ["あなたはモロウウィンドの世界の人物、{$actor}です。この世界があなたの現実です。{$actor}として行動し、{$player}の代わりに発言したり決断したりしないでください。", "{$actor}の次の台詞を書いてください。{$player}または直前の話者に返答し、会話の流れを踏まえ、以前の台詞を繰り返さないでください。"],
            default => null,
        };
    }
}
