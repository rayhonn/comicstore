<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_sections') {
        $fields = ['hero_subtitle', 'our_story', 'mission', 'stat_titles', 'stat_customers', 'stat_years', 'stat_rating'];
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $pdo->prepare("UPDATE about_sections SET section_content = ? WHERE section_key = ?")
                    ->execute([trim($_POST[$field]), $field]);
            }
        }
        $success = 'Content updated successfully!';

    } elseif ($action === 'add_award') {
        $emoji = trim($_POST['award_emoji'] ?? '🏆');
        $title = trim($_POST['award_title']);
        $org = trim($_POST['award_organization']);
        $result = trim($_POST['award_result']);
        $order = intval($_POST['award_order'] ?? 0);

        if (empty($title) || empty($org) || empty($result)) {
            $error = 'All award fields are required.';
        } else {
            $pdo->prepare("INSERT INTO about_awards (award_emoji, award_title, award_organization, award_result, award_order) VALUES (?, ?, ?, ?, ?)")
                ->execute([$emoji, $title, $org, $result, $order]);
            $success = 'Award added!';
        }

    } elseif ($action === 'edit_award') {
        $id = $_POST['award_id'];
        $pdo->prepare("UPDATE about_awards SET award_emoji=?, award_title=?, award_organization=?, award_result=?, award_order=? WHERE award_id=?")
            ->execute([trim($_POST['award_emoji']), trim($_POST['award_title']), trim($_POST['award_organization']), trim($_POST['award_result']), intval($_POST['award_order']), $id]);
        $success = 'Award updated!';

    } elseif ($action === 'delete_award') {
        $pdo->prepare("DELETE FROM about_awards WHERE award_id = ?")->execute([$_POST['award_id']]);
        $success = 'Award deleted.';

    } elseif ($action === 'add_team') {
        $pdo->prepare("INSERT INTO about_team (team_name, team_role, team_bio, team_initials, team_color, team_order) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([trim($_POST['team_name']), trim($_POST['team_role']), trim($_POST['team_bio']), trim($_POST['team_initials']), trim($_POST['team_color']), intval($_POST['team_order'])]);
        $success = 'Team member added!';

    } elseif ($action === 'edit_team') {
        $id = $_POST['team_id'];
        $pdo->prepare("UPDATE about_team SET team_name=?, team_role=?, team_bio=?, team_initials=?, team_color=?, team_order=? WHERE team_id=?")
            ->execute([trim($_POST['team_name']), trim($_POST['team_role']), trim($_POST['team_bio']), trim($_POST['team_initials']), trim($_POST['team_color']), intval($_POST['team_order']), $id]);
        $success = 'Team member updated!';

    } elseif ($action === 'delete_team') {
        $pdo->prepare("DELETE FROM about_team WHERE team_id = ?")->execute([$_POST['team_id']]);
        $success = 'Team member deleted.';
    }
}

$sections = $pdo->query("SELECT * FROM about_sections")->fetchAll(PDO::FETCH_ASSOC);
$s = [];
foreach ($sections as $sec) { $s[$sec['section_key']] = $sec['section_content']; }

$awards = $pdo->query("SELECT * FROM about_awards ORDER BY award_order ASC")->fetchAll(PDO::FETCH_ASSOC);
$team = $pdo->query("SELECT * FROM about_team ORDER BY team_order ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage About Us - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
        .modal { display: none; }
        .modal.active { display: flex; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-6xl mx-auto px-6 py-8">

        <div class="mb-6">
            <h1 class="text-2xl font-black text-gray-800">Manage About Us</h1>
            <p class="text-sm text-gray-400 mt-0.5">Edit your About Us page content</p>
        </div>

        <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-5">✅ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-5">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="flex gap-1 bg-white rounded-2xl shadow-sm p-1 mb-6 w-fit">
            <button onclick="switchTab('content')" id="tab-content"
                    class="px-5 py-2 rounded-xl text-sm font-semibold transition-colors bg-red-600 text-white">
                📝 Content
            </button>
            <button onclick="switchTab('awards')" id="tab-awards"
                    class="px-5 py-2 rounded-xl text-sm font-semibold transition-colors text-gray-500 hover:text-red-600">
                🏆 Awards
            </button>
            <button onclick="switchTab('team')" id="tab-team"
                    class="px-5 py-2 rounded-xl text-sm font-semibold transition-colors text-gray-500 hover:text-red-600">
                👥 Team
            </button>
        </div>

        <!-- Content Tab -->
        <div id="content-content" class="tab-content active">
            <form method="POST">
                <input type="hidden" name="action" value="update_sections">
                <div class="bg-white rounded-2xl shadow-sm p-6 space-y-5">
                    <h3 class="font-bold text-gray-800 mb-2">Page Content</h3>

                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Hero Subtitle</label>
                        <input type="text" name="hero_subtitle" value="<?= htmlspecialchars($s['hero_subtitle'] ?? '') ?>"
                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Our Story</label>
                        <textarea name="our_story" rows="5"
                                  class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white resize-none"><?= htmlspecialchars($s['our_story'] ?? '') ?></textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Mission Statement</label>
                        <textarea name="mission" rows="3"
                                  class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white resize-none"><?= htmlspecialchars($s['mission'] ?? '') ?></textarea>
                    </div>

                    <h3 class="font-bold text-gray-800 pt-2">Stats</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 mb-1.5">Titles Available</label>
                            <input type="text" name="stat_titles" value="<?= htmlspecialchars($s['stat_titles'] ?? '5K+') ?>"
                                   class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 mb-1.5">Happy Customers</label>
                            <input type="text" name="stat_customers" value="<?= htmlspecialchars($s['stat_customers'] ?? '50K+') ?>"
                                   class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 mb-1.5">Years in Business</label>
                            <input type="text" name="stat_years" value="<?= htmlspecialchars($s['stat_years'] ?? '4+') ?>"
                                   class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 mb-1.5">Average Rating</label>
                            <input type="text" name="stat_rating" value="<?= htmlspecialchars($s['stat_rating'] ?? '4.9★') ?>"
                                   class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                        </div>
                    </div>

                    <button type="submit"
                            class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-3 rounded-xl text-sm transition-colors">
                        Save Content
                    </button>
                </div>
            </form>
        </div>

        <!-- Awards Tab -->
        <div id="content-awards" class="tab-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="font-bold text-gray-700">Awards & Recognition</h3>
                <button onclick="openAwardModal()"
                        class="bg-red-600 hover:bg-red-700 text-white font-semibold px-4 py-2 rounded-xl text-sm transition-colors">
                    + Add Award
                </button>
            </div>
            <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                <?php if (empty($awards)): ?>
                <div class="p-12 text-center">
                    <div class="text-4xl mb-3">🏆</div>
                    <p class="text-gray-400 text-sm">No awards yet.</p>
                </div>
                <?php else: ?>
                <?php foreach ($awards as $i => $award): ?>
                <div class="flex items-center gap-4 p-4 <?= $i > 0 ? 'border-t border-gray-50' : '' ?>">
                    <span class="text-3xl"><?= htmlspecialchars($award['award_emoji']) ?></span>
                    <div class="flex-1 min-w-0">
                        <p class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($award['award_title']) ?></p>
                        <p class="text-xs text-gray-400"><?= htmlspecialchars($award['award_organization']) ?> · <span class="text-red-500"><?= htmlspecialchars($award['award_result']) ?></span></p>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="openEditAwardModal(<?= htmlspecialchars(json_encode($award)) ?>)"
                                class="text-xs px-3 py-1.5 border border-blue-200 text-blue-600 rounded-lg hover:bg-blue-50 transition-colors">✏️ Edit</button>
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="delete_award">
                            <input type="hidden" name="award_id" value="<?= $award['award_id'] ?>">
                            <button type="submit" onclick="return confirm('Delete this award?')"
                                    class="text-xs px-3 py-1.5 border border-red-200 text-red-600 rounded-lg hover:bg-red-50 transition-colors">🗑️ Delete</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Team Tab -->
        <div id="content-team" class="tab-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="font-bold text-gray-700">Team Members</h3>
                <button onclick="openTeamModal()"
                        class="bg-red-600 hover:bg-red-700 text-white font-semibold px-4 py-2 rounded-xl text-sm transition-colors">
                    + Add Member
                </button>
            </div>
            <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                <?php if (empty($team)): ?>
                <div class="p-12 text-center">
                    <div class="text-4xl mb-3">👥</div>
                    <p class="text-gray-400 text-sm">No team members yet.</p>
                </div>
                <?php else: ?>
                <?php foreach ($team as $i => $member): ?>
                <div class="flex items-center gap-4 p-4 <?= $i > 0 ? 'border-t border-gray-50' : '' ?>">
                    <div class="w-12 h-12 rounded-full flex items-center justify-center text-white font-black text-sm flex-shrink-0"
                         style="background: <?= htmlspecialchars($member['team_color']) ?>">
                        <?= htmlspecialchars($member['team_initials']) ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($member['team_name']) ?></p>
                        <p class="text-xs text-red-500"><?= htmlspecialchars($member['team_role']) ?></p>
                        <p class="text-xs text-gray-400 truncate"><?= htmlspecialchars($member['team_bio']) ?></p>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="openEditTeamModal(<?= htmlspecialchars(json_encode($member)) ?>)"
                                class="text-xs px-3 py-1.5 border border-blue-200 text-blue-600 rounded-lg hover:bg-blue-50 transition-colors">✏️ Edit</button>
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="delete_team">
                            <input type="hidden" name="team_id" value="<?= $member['team_id'] ?>">
                            <button type="submit" onclick="return confirm('Delete this team member?')"
                                    class="text-xs px-3 py-1.5 border border-red-200 text-red-600 rounded-lg hover:bg-red-50 transition-colors">🗑️ Delete</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Award Modal -->
    <div id="awardModal" class="modal fixed inset-0 bg-black/50 z-50 items-center justify-center px-4">
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl">
            <div class="p-5 border-b border-gray-100 flex justify-between items-center">
                <h3 class="font-black text-gray-800" id="awardModalTitle">Add Award</h3>
                <button onclick="closeAwardModal()" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>
            <form method="POST" class="p-5 space-y-4">
                <input type="hidden" name="action" id="awardAction" value="add_award">
                <input type="hidden" name="award_id" id="awardId">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5">Emoji</label>
                        <input type="text" name="award_emoji" id="awardEmoji" value="🏆" maxlength="5"
                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5">Order</label>
                        <input type="number" name="award_order" id="awardOrder" value="0"
                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5">Award Title *</label>
                    <input type="text" name="award_title" id="awardTitle" required
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5">Organization *</label>
                    <input type="text" name="award_organization" id="awardOrg" required
                           placeholder="e.g. Malaysia Digital Awards 2023"
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5">Result *</label>
                    <input type="text" name="award_result" id="awardResult" required
                           placeholder="e.g. Gold Award, Winner, 1st Place"
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                </div>
                <div class="flex gap-3">
                    <button type="button" onclick="closeAwardModal()"
                            class="flex-1 py-3 border-2 border-gray-100 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50">Cancel</button>
                    <button type="submit"
                            class="flex-1 py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Team Modal -->
    <div id="teamModal" class="modal fixed inset-0 bg-black/50 z-50 items-center justify-center px-4">
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl">
            <div class="p-5 border-b border-gray-100 flex justify-between items-center">
                <h3 class="font-black text-gray-800" id="teamModalTitle">Add Team Member</h3>
                <button onclick="closeTeamModal()" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>
            <form method="POST" class="p-5 space-y-4">
                <input type="hidden" name="action" id="teamAction" value="add_team">
                <input type="hidden" name="team_id" id="teamId">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5">Full Name *</label>
                        <input type="text" name="team_name" id="teamName" required
                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1.5">Initials *</label>
                        <input type="text" name="team_initials" id="teamInitials" required maxlength="3"
                               placeholder="e.g. RH"
                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5">Role *</label>
                    <input type="text" name="team_role" id="teamRole" required
                           placeholder="e.g. CEO & Co-Founder"
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5">Bio</label>
                    <input type="text" name="team_bio" id="teamBio"
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5">Avatar Color (CSS gradient)</label>
                    <input type="text" name="team_color" id="teamColor" value="linear-gradient(135deg, #1e2d4a, #2c3e6b)"
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5">Display Order</label>
                    <input type="number" name="team_order" id="teamOrder" value="0"
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white">
                </div>
                <div class="flex gap-3">
                    <button type="button" onclick="closeTeamModal()"
                            class="flex-1 py-3 border-2 border-gray-100 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50">Cancel</button>
                    <button type="submit"
                            class="flex-1 py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function switchTab(tab) {
        ['content','awards','team'].forEach(t => {
            document.getElementById('tab-' + t).className = 'px-5 py-2 rounded-xl text-sm font-semibold transition-colors ' +
                (t === tab ? 'bg-red-600 text-white' : 'text-gray-500 hover:text-red-600');
            document.getElementById('content-' + t).classList.toggle('active', t === tab);
        });
    }

    function openAwardModal() {
        document.getElementById('awardModalTitle').textContent = 'Add Award';
        document.getElementById('awardAction').value = 'add_award';
        document.getElementById('awardId').value = '';
        document.getElementById('awardEmoji').value = '🏆';
        document.getElementById('awardTitle').value = '';
        document.getElementById('awardOrg').value = '';
        document.getElementById('awardResult').value = '';
        document.getElementById('awardOrder').value = '0';
        document.getElementById('awardModal').classList.add('active');
    }
    function openEditAwardModal(a) {
        document.getElementById('awardModalTitle').textContent = 'Edit Award';
        document.getElementById('awardAction').value = 'edit_award';
        document.getElementById('awardId').value = a.award_id;
        document.getElementById('awardEmoji').value = a.award_emoji;
        document.getElementById('awardTitle').value = a.award_title;
        document.getElementById('awardOrg').value = a.award_organization;
        document.getElementById('awardResult').value = a.award_result;
        document.getElementById('awardOrder').value = a.award_order;
        document.getElementById('awardModal').classList.add('active');
    }
    function closeAwardModal() { document.getElementById('awardModal').classList.remove('active'); }

    function openTeamModal() {
        document.getElementById('teamModalTitle').textContent = 'Add Team Member';
        document.getElementById('teamAction').value = 'add_team';
        document.getElementById('teamId').value = '';
        document.getElementById('teamName').value = '';
        document.getElementById('teamInitials').value = '';
        document.getElementById('teamRole').value = '';
        document.getElementById('teamBio').value = '';
        document.getElementById('teamColor').value = 'linear-gradient(135deg, #1e2d4a, #2c3e6b)';
        document.getElementById('teamOrder').value = '0';
        document.getElementById('teamModal').classList.add('active');
    }
    function openEditTeamModal(m) {
        document.getElementById('teamModalTitle').textContent = 'Edit Team Member';
        document.getElementById('teamAction').value = 'edit_team';
        document.getElementById('teamId').value = m.team_id;
        document.getElementById('teamName').value = m.team_name;
        document.getElementById('teamInitials').value = m.team_initials;
        document.getElementById('teamRole').value = m.team_role;
        document.getElementById('teamBio').value = m.team_bio || '';
        document.getElementById('teamColor').value = m.team_color;
        document.getElementById('teamOrder').value = m.team_order;
        document.getElementById('teamModal').classList.add('active');
    }
    function closeTeamModal() { document.getElementById('teamModal').classList.remove('active'); }

    document.getElementById('awardModal').addEventListener('click', function(e) { if (e.target === this) closeAwardModal(); });
    document.getElementById('teamModal').addEventListener('click', function(e) { if (e.target === this) closeTeamModal(); });
    </script>
</body>
</html>