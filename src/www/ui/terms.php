<details id="terms-">
    <summary>Terms of use</summary>
    <div class="card"> 
        <h2>Terms of use</h2>
        <?php if (($_GET['saveterms'] ?? "") == 1): echo infoAlert();
        endif; ?>
        <?php
// Načti aktuální terms z DB
        $terms_raw = $configdb['terms'] ?? '{}';
        $terms_json = json_decode($terms_raw, true) ?? [];
        $terms_lng = $terms_json['lng'] ?? [];

// Mapa kódů na názvy jazyků
        $all_languages = [
            'af' => 'Afrikaans', 'sq' => 'Albanian', 'am' => 'Amharic',
            'ar' => 'Arabic', 'hy' => 'Armenian', 'az' => 'Azerbaijani',
            'eu' => 'Basque', 'be' => 'Belarusian', 'bn' => 'Bengali',
            'bs' => 'Bosnian', 'bg' => 'Bulgarian', 'ca' => 'Catalan',
            'zh' => 'Chinese', 'hr' => 'Croatian', 'cs' => 'Czech',
            'da' => 'Danish', 'nl' => 'Dutch', 'en' => 'English',
            'et' => 'Estonian', 'fi' => 'Finnish', 'fr' => 'French',
            'gl' => 'Galician', 'ka' => 'Georgian', 'de' => 'German',
            'el' => 'Greek', 'gu' => 'Gujarati', 'ht' => 'Haitian Creole',
            'ha' => 'Hausa', 'he' => 'Hebrew', 'hi' => 'Hindi',
            'hu' => 'Hungarian', 'is' => 'Icelandic', 'id' => 'Indonesian',
            'ga' => 'Irish', 'it' => 'Italian', 'ja' => 'Japanese',
            'kn' => 'Kannada', 'kk' => 'Kazakh', 'km' => 'Khmer',
            'ko' => 'Korean', 'ku' => 'Kurdish', 'ky' => 'Kyrgyz',
            'lo' => 'Lao', 'lv' => 'Latvian', 'lt' => 'Lithuanian',
            'mk' => 'Macedonian', 'ms' => 'Malay', 'ml' => 'Malayalam',
            'mt' => 'Maltese', 'mi' => 'Maori', 'mr' => 'Marathi',
            'mn' => 'Mongolian', 'ne' => 'Nepali', 'nb' => 'Norwegian',
            'ps' => 'Pashto', 'fa' => 'Persian', 'pl' => 'Polish',
            'pt' => 'Portuguese', 'pa' => 'Punjabi', 'ro' => 'Romanian',
            'ru' => 'Russian', 'sm' => 'Samoan', 'sr' => 'Serbian',
            'sk' => 'Slovak', 'sl' => 'Slovenian', 'so' => 'Somali',
            'es' => 'Spanish', 'sw' => 'Swahili', 'sv' => 'Swedish',
            'tl' => 'Tagalog', 'tg' => 'Tajik', 'ta' => 'Tamil',
            'tt' => 'Tatar', 'te' => 'Telugu', 'th' => 'Thai',
            'tr' => 'Turkish', 'tk' => 'Turkmen', 'uk' => 'Ukrainian',
            'ur' => 'Urdu', 'uz' => 'Uzbek', 'vi' => 'Vietnamese',
            'cy' => 'Welsh', 'xh' => 'Xhosa', 'yi' => 'Yiddish',
            'yo' => 'Yoruba', 'zu' => 'Zulu',
        ];

// Pomocná funkce — vrátí název jazyka podle kódu
        function lang_name(string $code, array $all): string {
            return $all[$code] ?? strtoupper($code);
        }

// Jazyky dostupné pro přidání (ještě nepoužité)
        $available_languages = array_filter(
                $all_languages,
                fn($code) => !isset($terms_lng[$code]),
                ARRAY_FILTER_USE_KEY
        );

// Seřaď existující jazyky abecedně podle názvu
        uksort($terms_lng, fn($a, $b) =>
                strcmp(lang_name($a, $all_languages), lang_name($b, $all_languages))
        );
        ?>

        <?php if (!$terms_lng): ?>
            <p class="alert-muted" style="font-size:0.85rem; margin-bottom:0.75rem">
                No terms defined yet. Add a language below.
            </p>
        <?php endif; ?>

        <!-- Existující jazyky -->
        <?php foreach ($terms_lng as $lang => $text): ?>
            <details id="terms-<?= htmlspecialchars($lang) ?>">
                <summary><?= htmlspecialchars(lang_name($lang, $all_languages)) ?></summary>
                <div class="card">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="edit_terms_lang">
                        <input type="hidden" name="lang" value="<?= htmlspecialchars($lang) ?>">

                        <label>Terms text:</label>
                        <textarea name="text" class="mono-input" rows="6"><?= htmlspecialchars($text) ?></textarea>

                        <label style="display:flex; align-items:center; gap:0.6rem; cursor:pointer; margin-bottom:0.5rem">
                            <input type="checkbox"
                                   name="require_sign"
                                   value="1"
                                   style="width:auto; margin:0">
                            Require clients to sign new terms of use
                        </label>

                        <div class="deadline-row" style="display:flex; flex-direction:column; gap:0.3rem; margin-bottom:0.75rem">
                            <label style="margin:0; white-space:nowrap">Days to sign before invoicing is blocked:</label>
                            <input type="number"
                                   name="sign_deadline_days"
                                   min="1" max="365"
                                   value="30">
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:0.75rem">
                            <button class="btn btn-danger" type="submit" name="action" value="delete_terms_lang"
                                    onclick="return confirm('Remove <?= htmlspecialchars(lang_name($lang, $all_languages)) ?> terms?')">Remove</button>
                            <button class="btn btn-secondary" type="submit" name="action" value="edit_terms_lang">Save</button>
                        </div>
                    </form>
                </div>
            </details>
        <?php endforeach; ?>

        <!-- Přidat nový jazyk -->
        <?php if ($available_languages): ?>
            <details>
                <summary>➕ Add language</summary>
                <div class="card">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_terms_lang">

                        <label>Language:</label>
                        <select name="lang" class="mono">
                            <?php foreach ($available_languages as $code => $name): ?>
                                <option value="<?= htmlspecialchars($code) ?>">
                                    <?= htmlspecialchars($name) ?> (<?= htmlspecialchars($code) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label>Terms text:</label>
                        <textarea name="text" class="mono-input" rows="6" placeholder="Enter terms of use..."></textarea>

                        <div style="display:flex; justify-content:flex-end; margin-top:0.75rem">
                            <button class="btn">Add</button>
                        </div>
                    </form>
                </div>
            </details>
        <?php endif; ?>

    </div>
</details>

<script>
    document.querySelectorAll('input[name="require_sign"]').forEach(function (cb) {
        var row = cb.closest('form').querySelector('.deadline-row');
        row.style.display = cb.checked ? 'flex' : 'none';
        cb.addEventListener('change', function () {
            row.style.display = cb.checked ? 'flex' : 'none';
        });
    });

</script>