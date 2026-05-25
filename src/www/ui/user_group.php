<details>
    <summary>User groups</summary>
    <div class="card">
        <?php if (($_GET['saveusergroup'] ?? "") == 1): echo infoAlert();
        endif; ?>
<?php foreach ($usergroup_t as $row): ?>

            <details id="usergroup-<?= htmlspecialchars($row['level']) ?>">
                <summary><?= htmlspecialchars($row['name']) ?></summary>

                <div class="card">
                    <form method="post" action="<?= strtok($_SERVER['REQUEST_URI'], '?') ?>">
    <?= csrf_field() ?>
                        <input type="hidden" name="action" value="edit_user_group">
                        <input type="hidden" name="level" value="<?= htmlspecialchars($row['level']) ?>">
                        <label>Description:</label>
                        <input class="editable" name="name" value="<?= htmlspecialchars($row['name']) ?>" required>
                       

                        <label>Max. ballance SAT:</label>
                        <input type="number" min="0" max="1000000000" name="max_balance_sat"
                               value="<?= htmlspecialchars($row['max_balance_sat']) ?>">

                        <label>Fee ppm:</label>
                        <input type="number" min="0" max="10000" name="fee_ppm"
                               value="<?= htmlspecialchars($row['fee_ppm']) ?>">

                        <label style="margin:0; white-space:nowrap">Days to sign new terms of use before invoicing is blocked:</label>
                        <input type="number"
                               name="sign_deadline_days"
                               min="1" max="365"
                               value="30"        >


                        <label style="margin-bottom:0.75rem">
                            Changes to maximum balance or fees require clients to sign new terms of use
                        </label>

                        <div style="display:flex; justify-content:flex-end;">
                            <button class="btn btn-secondary" name="saveNodeName">Save</button>
                        </div>
                    </form>
                </div>

            </details>

<?php endforeach; ?>
    </div>

</details>

