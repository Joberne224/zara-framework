<?php
declare(strict_types=1);

namespace Zara\Core\Support;

/**
 * Génère des liens HTML cliquables qui ouvrent un éditeur de code à un
 * fichier:ligne précis, via les schémas d'URI personnalisés que chaque
 * éditeur enregistre auprès du système d'exploitation.
 *
 * Le navigateur délègue l'URI au programme associé — aucune communication
 * réseau, tout se passe localement entre le navigateur et l'éditeur installé.
 *
 * Utilisée à la fois par HtmlDevRenderer (stack traces) et VarDumper
 * (origine d'un appel dump()/dd()) — extraite ici pour éviter la duplication
 * du tableau de presets entre les deux.
 */
final class EditorLink
{
    /**
     * %file% et %line% sont remplacés par le chemin absolu et le numéro de ligne.
     */
    private const PRESETS = [
        'vscode'          => 'vscode://file/%file%:%line%',
        'vscode-insiders' => 'vscode-insiders://file/%file%:%line%',
        'cursor'          => 'cursor://file/%file%:%line%',
        'phpstorm'        => 'phpstorm://open?file=%file%&line=%line%',
        'sublime'         => 'subl://open?url=file://%file%&line=%line%',
        'textmate'        => 'txmt://open?url=file://%file%&line=%line%',
        'atom'            => 'atom://core/open/file?filename=%file%&line=%line%',
    ];

    /**
     * Construit un lien cliquable. Si le fichier est vide ou interne
     * (ex. '[internal]', appel natif PHP sans fichier), retourne le
     * contenu tel quel, sans wrapper <a>.
     *
     * @param string $editor    Clé de PRESETS, ou un template brut contenant %file%/%line%
     * @param string $file      Chemin absolu du fichier (non échappé)
     * @param int|string $line  Numéro de ligne
     * @param string $innerHtml Contenu HTML déjà préparé (déjà échappé si besoin)
     */
    public static function wrap(string $editor, string $file, int|string $line, string $innerHtml): string
    {
        if ($file === '' || $file === '[internal]') {
            return $innerHtml;
        }

        $template = self::PRESETS[$editor] ?? $editor; // permet un template custom direct

        $href = str_replace(['%file%', '%line%'], [$file, (string) $line], $template);
        $href = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');

        // stopPropagation : ces liens apparaissent parfois dans des éléments
        // cliquables (ex. l'en-tête dépliable d'une frame de stack trace) —
        // sans ça, cliquer le lien ouvrirait l'éditeur ET togglerait l'élément parent.
        return "<a class=\"editor-link\" href=\"{$href}\" title=\"Ouvrir dans l'éditeur\" onclick=\"event.stopPropagation()\">{$innerHtml}</a>";
    }

    /**
     * Bloc CSS partagé à inclure une fois dans la page (ou réutilisable tel quel).
     */
    public static function css(): string
    {
        return <<<CSS
        .editor-link { color: inherit; text-decoration: none; border-bottom: 1px dashed transparent; transition: border-color .15s; cursor: pointer; }
        .editor-link:hover { border-bottom-color: currentColor; }
        .editor-link:hover::after { content: ' ↗'; opacity: .6; }
        CSS;
    }
}