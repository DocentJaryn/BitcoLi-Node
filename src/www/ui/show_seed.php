<details>
    <summary>Recovery phrase (seed)</summary>
    <div class="card">

        <div>
            <div style="display:flex; align-items:center; justify-content:space-between;">
                <h2 style="margin:0;">Seed (BIP39)</h2>

                <button class="btn btn-secondary" id="toggleSeedBtn">
                    Show seed
                </button>
            </div>
            <textarea id="seedTextarea" rows="3" readonly style="display:none; margin-top:0" ><?= htmlspecialchars($mnemonic) ?></textarea>
        </div>


        <!-- Tla��tko pro zobrazen� / skryt� -->

        <!-- Textarea se seedem, na za��tku skryt� -->


    </div>
</details>

<script>
    const btn = document.getElementById('toggleSeedBtn');
    const textarea = document.getElementById('seedTextarea');

    btn.addEventListener('click', () => {
        if (textarea.style.display === 'none') {
            textarea.style.display = 'block';
            btn.textContent = 'Hide seed';
        } else {
            textarea.style.display = 'none';
            btn.textContent = 'Show seed';
        }
    });
</script>