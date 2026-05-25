<details>
    <summary>Invitations</summary>
    <div class="card">

        <?php foreach ($invitations_t as $row): ?>

            <details>
                <summary><?= htmlspecialchars($row['description'] . ' (' . $row['used'] . '/' . $row['cnt'] . ')') ?></summary>

                <div class="card">
                    <form method="post" action="">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="edit_invitation">
                        <input type="hidden" name="idx" value="<?= htmlspecialchars($row['idx']) ?>">
                        <label>ID:</label>
                        <input class="editable" name="id" contenteditable="true" value="<?= htmlspecialchars($row['id']) ?>">
                        </input>

                        <label>Description:</label>
                        <input class="editable" name="description" contenteditable="true" value="<?= htmlspecialchars($row['description']) ?>">
                        </code>

                        <label>Number of invitations:</label>
                        <input type="number" name="cnt" min="1" max="1000"
                               value="<?= htmlspecialchars($row['cnt']) ?>">

                        <label>Used invitations:</label>
                        <code><?= htmlspecialchars($row['used']) ?></code>

                        <label>Group:</label>
                        <select name="level" class="mono">
                            <?php foreach ($usergroup_t as $row2): ?>
                                <option value="<?= $row2['level'] ?>" <?= $row['level'] == $row2['level'] ? 'selected' : '' ?>><?= htmlspecialchars($row2['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div style="display:flex; justify-content:flex-end;">
                            <button class="btn btn-secondary" name="saveNodeName">Save</button>
                        </div>
                    </form>  
                </div>

            </details>

        <?php endforeach; ?>
        <details>
            <summary>➕ Add new invitation</summary>
            <div class="card">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_invitation">

                    <label>Description:</label>
                    <input class="editable" name="description" contenteditable="true" value="" required>
                    </code>

                    <label>Number of invitations:</label>
                    <input type="number" name="cnt" min="1" max="1000" value="10">

                    <label>Group:</label>
                    <select name="level" class="mono">
                        <?php foreach ($usergroup_t as $row2): ?>
                            <option value="<?= $row2['level'] ?>" <?= $row2['level'] == $usergroup_t[0]['level'] ? 'selected' : '' ?>><?= htmlspecialchars($row2['name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div style="display:flex; justify-content:flex-end;">
                        <button class="btn btn-primary">Create</button>
                    </div>
                </form>
            </div>
        </details>

    </div>
</details>
