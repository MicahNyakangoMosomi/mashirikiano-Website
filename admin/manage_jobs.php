<?php
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/admin_layout.php';

Auth::requireAdmin();
Auth::startSession();

$pdo = Database::connection();

// Ensure jobs table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS `jobs` (
    `job_id` INT AUTO_INCREMENT PRIMARY KEY,
    `job_title` VARCHAR(255) NOT NULL,
    `job_category` ENUM('internship', 'attachment', 'job') NOT NULL,
    `job_description` TEXT NOT NULL,
    `job_requirements` TEXT NOT NULL,
    `job_deadline` DATE NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$message = '';
$error = '';

if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// Handle Job Posting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_job'])) {
    $title = trim($_POST['job_title']);
    $category = $_POST['job_category'];
    $description = trim($_POST['job_description']);
    $requirements = trim($_POST['job_requirements']);
    $deadline = $_POST['job_deadline'];

    if ($title && $category && $description && $requirements && $deadline) {
        $stmt = $pdo->prepare("INSERT INTO jobs (job_title, job_category, job_description, job_requirements, job_deadline) VALUES (?, ?, ?, ?, ?)");
        if ($stmt->execute([$title, $category, $description, $requirements, $deadline])) {
            $_SESSION['flash_message'] = "Job posted successfully!";
        } else {
            $_SESSION['flash_error'] = "Failed to post job.";
        }
    } else {
        $_SESSION['flash_error'] = "Please fill in all fields.";
    }

    header('Location: manage_jobs.php');
    exit;
}

// Handle Job Deletion
if (isset($_GET['delete'])) {
    $jobId = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM jobs WHERE job_id = ?");
    if ($stmt->execute([$jobId])) {
        $_SESSION['flash_message'] = "Job deleted successfully!";
    }

    header('Location: manage_jobs.php');
    exit;
}

// Fetch all jobs
$jobs = $pdo->query("SELECT * FROM jobs ORDER BY job_deadline ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Jobs | Admin</title>
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/main.css" rel="stylesheet">
    <link href="admin.css?v=20261120-6" rel="stylesheet">
    <style>
        .admin-card { background: white; padding: 25px; margin-bottom: 30px; }
    </style>
</head>
<body>

<?php admin_header('manage_jobs', 'Job posting management'); ?>

<div class="container-fluid admin-shell py-4">
    <?php if ($message): ?>
        <div class="alert alert-success"><?= admin_e($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= admin_e($error) ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-5">
            <div class="admin-card">
                <h4>Post New Job</h4>
                <hr>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Job Title</label>
                        <input type="text" name="job_title" class="form-control" placeholder="e.g. IT Assistant" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <select name="job_category" class="form-select" required>
                            <option value="job">Job Opening</option>
                            <option value="internship">Internship</option>
                            <option value="attachment">Attachment</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Job Description</label>
                        <textarea name="job_description" class="form-control" rows="3" placeholder="What will the applicant do?" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Job Requirements</label>
                        <textarea name="job_requirements" class="form-control" rows="3" placeholder="List requirements (one per line)" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Deadline</label>
                        <input type="date" name="job_deadline" class="form-control" required>
                    </div>
                    <button type="submit" name="post_job" class="btn btn-primary w-100">Post Job Opportunity</button>
                </form>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="admin-card">
                <h4>Existing Postings</h4>
                <hr>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Category</th>
                                <th>Deadline</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($jobs as $job): ?>
                                <tr>
                                    <td><?= admin_e($job['job_title']) ?></td>
                                    <td><span class="badge bg-secondary"><?= admin_e(ucfirst($job['job_category'])) ?></span></td>
                                    <td>
                                        <?php 
                                        $deadline = strtotime($job['job_deadline']);
                                        $isExpired = $deadline < time();
                                        ?>
                                        <span class="<?= $isExpired ? 'text-danger fw-bold' : '' ?>">
                                            <?= date('d M Y', $deadline) ?>
                                            <?= $isExpired ? ' (Expired)' : '' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="?delete=<?= $job['job_id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this posting?')">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($jobs)): ?>
                                <tr><td colspan="4" class="text-center">No jobs posted yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
