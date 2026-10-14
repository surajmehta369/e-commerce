
<?php
require_once "auth.php";
require_once "../connection/dbconnect.php";

$database = new Database();
$db = $database->connect();

$message = "";
$messageType = "success";
function createBrandSlug($name)
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-');
}
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    try {
        if ($action === "save") {
            $id = (int)($_POST["id"] ?? 0);
            $name = trim($_POST["name"] ?? "");

            if ($name === "" || strlen($name) > 100) {
                throw new Exception(
                    "Enter a brand name (maximum 100 characters)."
                );
            }

            $slug = createBrandSlug($name);

            if ($slug === "") {
                throw new Exception(
                    "Please enter a brand name containing letters or numbers."
                );
            }
            $baseSlug = $slug;
            $counter = 1;

            while (true) {
                $sql = "SELECT id FROM brands WHERE slug = ?";
                $params = [$slug];

                if ($id > 0) {
                    $sql .= " AND id != ?";
                    $params[] = $id;
                }

                $stmt = $db->prepare($sql);
                $stmt->execute($params);

                if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                    break;
                }

                $slug = $baseSlug . "-" . $counter;
                $counter++;
            }

            if ($id > 0) {
                $stmt = $db->prepare(
                    "UPDATE brands SET name = ?, slug = ? WHERE id = ?"
                );
                $stmt->execute([$name, $slug, $id]);

                $message = "Brand updated successfully.";
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO brands (name, slug, status)
                     VALUES (?, ?, 1)"
                );
                $stmt->execute([$name, $slug]);

                $message = "Brand added successfully.";
            }
        }

        elseif ($action === "toggle") {
            $id = (int)($_POST["id"] ?? 0);

            $stmt = $db->prepare(
                "SELECT status FROM brands WHERE id = ?"
            );
            $stmt->execute([$id]);
            $brand = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$brand) {
                throw new Exception("Brand not found.");
            }

            $newStatus = (int)$brand["status"] === 1 ? 0 : 1;
            if ($newStatus === 0) {
                $stmt = $db->prepare(
                    "SELECT COUNT(*) FROM products WHERE brand_id = ?"
                );
                $stmt->execute([$id]);

                if ((int)$stmt->fetchColumn() > 0) {
                    throw new Exception(
                        "This brand has products assigned to it. Reassign those products before deactivating the brand."
                    );
                }
            }

            $stmt = $db->prepare(
                "UPDATE brands SET status = ? WHERE id = ?"
            );
            $stmt->execute([$newStatus, $id]);

            $message = $newStatus
                ? "Brand activated successfully."
                : "Brand deactivated successfully.";
        }

        elseif ($action === "delete") {
            $id = (int)($_POST["id"] ?? 0);

            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM products WHERE brand_id = ?"
            );
            $stmt->execute([$id]);

            if ((int)$stmt->fetchColumn() > 0) {
                throw new Exception(
                    "Cannot delete this brand because products are assigned to it."
                );
            }

            $stmt = $db->prepare(
                "DELETE FROM brands WHERE id = ?"
            );
            $stmt->execute([$id]);

            $message = "Brand deleted successfully.";
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = "danger";
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION["brand_message"] = $message;
    $_SESSION["brand_message_type"] = $messageType;

    header("Location: brands.php");
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION["brand_message"])) {
    $message = $_SESSION["brand_message"];
    $messageType = $_SESSION["brand_message_type"] ?? "success";

    unset($_SESSION["brand_message"]);
    unset($_SESSION["brand_message_type"]);
}
$search = trim($_GET["search"] ?? "");

$sql = "
    SELECT
        b.id,
        b.name,
        b.slug,
        b.status,
        b.created_at,
        (
            SELECT COUNT(*)
            FROM products p
            WHERE p.brand_id = b.id
        ) AS product_count
    FROM brands b
";

$params = [];

if ($search !== "") {
    $sql .= " WHERE b.name LIKE ? OR b.slug LIKE ?";
    $params = ["%" . $search . "%", "%" . $search . "%"];
}

$sql .= " ORDER BY b.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$brands = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Dashboard counts
$totalBrands = (int)$db->query(
    "SELECT COUNT(*) FROM brands"
)->fetchColumn();

$activeBrands = (int)$db->query(
    "SELECT COUNT(*) FROM brands WHERE status = 1"
)->fetchColumn();

$inactiveBrands = $totalBrands - $activeBrands;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Brands | Admin Panel</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <style>
        body {
            margin: 0;
            background: #f5f7fb;
            font-family: Arial, sans-serif;
            color: #1f2937;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 240px;
            height: 100vh;
            overflow-y: auto;
            background: #111827;
            padding: 24px 14px;
            color: #fff;
        }

        .sidebar h4 {
            font-size: 20px;
            margin: 0 10px 28px;
            font-weight: 700;
        }

        .sidebar a {
            display: block;
            padding: 12px 14px;
            margin-bottom: 5px;
            border-radius: 8px;
            color: #cbd5e1;
            text-decoration: none;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #374151;
            color: #fff;
        }

        .main {
            margin-left: 240px;
            padding: 30px;
        }

        .stat-card,
        .content-card {
            background: #fff;
            border: 0;
            border-radius: 14px;
            box-shadow: 0 3px 15px rgba(15, 23, 42, .05);
        }

        .stat-card {
            padding: 22px;
            height: 100%;
        }

        .stat-number {
            font-size: 28px;
            font-weight: 700;
            margin-top: 8px;
        }

        .content-card {
            padding: 24px;
        }

        .table th {
            font-size: 13px;
            color: #64748b;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .table td {
            vertical-align: middle;
        }

        .brand-name {
            font-weight: 600;
        }

        .brand-slug {
            color: #64748b;
            font-size: 13px;
        }

        @media (max-width: 800px) {
            .sidebar {
                position: static;
                width: 100%;
                height: auto;
            }

            .main {
                margin-left: 0;
                padding: 18px;
            }
        }
    </style>
</head>

<body>

<aside class="sidebar">
    <h4>Admin Panel</h4>

    <a href="index.php">Dashboard</a>
    <a href="vendors.php">Vendors</a>
    <a href="products.php">Products</a>
    <a href="customers.php">Customers</a>
    <a href="categories.php">Categories</a>
    <a href="brands.php" class="active">Brands</a>
    <a href="orders.php">Orders</a>
    <a href="profile.php">Admin Profile</a>
    <a href="../outh/logout.php">Logout</a>
</aside>

<main class="main">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h2 class="fw-bold mb-1">Brands</h2>
            <p class="text-secondary mb-0">
                Manage product brands in your store.
            </p>
        </div>

        <button class="btn btn-primary"
                data-bs-toggle="modal"
                data-bs-target="#brandModal"
                onclick="prepareAdd()">
            + Add Brand
        </button>
    </div>

    <?php if ($message !== ""): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close"
                    data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <div class="row g-3 mb-4">

        <div class="col-md-4">
            <div class="stat-card">
                <div class="text-secondary">Total Brands</div>
                <div class="stat-number"><?= $totalBrands ?></div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="stat-card">
                <div class="text-secondary">Active Brands</div>
                <div class="stat-number text-success"><?= $activeBrands ?></div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="stat-card">
                <div class="text-secondary">Inactive Brands</div>
                <div class="stat-number text-danger"><?= $inactiveBrands ?></div>
            </div>
        </div>

    </div>
    <section class="content-card">

        <form method="GET" class="row g-2 mb-4">
            <div class="col-md-5">
                <input type="text"
                       name="search"
                       class="form-control"
                       placeholder="Search brand name or slug..."
                       value="<?= htmlspecialchars($search) ?>">
            </div>

            <div class="col-auto">
                <button type="submit" class="btn btn-dark">Search</button>
                <a href="brands.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle">

                <thead>
                <tr>
                    <th>S.No.</th>
                    <th>Brand Name</th>
                    <th>Slug</th>
                    <th>Products</th>
                    <th>Status</th>
                    <th>Created Date</th>
                    <th>Actions</th>
                </tr>
                </thead>

                <tbody>

                <?php if (count($brands) > 0): ?>
                    <?php $serialNumber = 1; ?>

                    <?php foreach ($brands as $brand): ?>
                        <tr>
                            <td><?= $serialNumber++ ?></td>

                            <td>
                                <span class="brand-name">
                                    <?= htmlspecialchars($brand["name"]) ?>
                                </span>
                            </td>

                            <td>
                                <span class="brand-slug">
                                    <?= htmlspecialchars($brand["slug"]) ?>
                                </span>
                            </td>

                            <td><?= (int)$brand["product_count"] ?></td>

                            <td>
                                <?php if ((int)$brand["status"] === 1): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    date("d M Y", strtotime($brand["created_at"]))
                                ) ?>
                            </td>

                            <td>
                                <div class="d-flex flex-wrap gap-1">

                                    <button type="button"
                                            class="btn btn-sm btn-outline-primary"
                                            data-bs-toggle="modal"
                                            data-bs-target="#brandModal"
                                            onclick='prepareEdit(
                                                <?= (int)$brand["id"] ?>,
                                                <?= json_encode($brand["name"]) ?>
                                            )'>
                                        Edit
                                    </button>

                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id"
                                               value="<?= (int)$brand["id"] ?>">

                                        <button type="submit"
                                                class="btn btn-sm btn-outline-warning"
                                                onclick="return confirm('Change this brand status?')">
                                            <?= (int)$brand["status"] === 1
                                                ? "Deactivate"
                                                : "Activate" ?>
                                        </button>
                                    </form>

                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id"
                                               value="<?= (int)$brand["id"] ?>">

                                        <button type="submit"
                                                class="btn btn-sm btn-outline-danger"
                                                onclick="return confirm('Are you sure you want to delete this brand?')">
                                            Delete
                                        </button>
                                    </form>

                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php else: ?>
                    <tr>
                        <td colspan="7"
                            class="text-center text-secondary py-4">
                            No brands found.
                        </td>
                    </tr>
                <?php endif; ?>

                </tbody>
            </table>
        </div>

    </section>

</main>
<div class="modal fade" id="brandModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 rounded-4">

            <form method="POST">

                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Brand</h5>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" id="brandId" value="0">

                    <div class="mb-3">
                        <label for="brandName" class="form-label">
                            Brand Name
                        </label>

                        <input type="text"
                               name="name"
                               id="brandName"
                               class="form-control"
                               maxlength="100"
                               required>
                    </div>

                    <small class="text-secondary">
                        The brand slug will be generated automatically.
                    </small>

                </div>

                <div class="modal-footer">
                    <button type="button"
                            class="btn btn-light"
                            data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit" class="btn btn-primary">
                        Save Brand
                    </button>
                </div>

            </form>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
function prepareAdd() {
    document.getElementById("modalTitle").textContent = "Add Brand";
    document.getElementById("brandId").value = "0";
    document.getElementById("brandName").value = "";
}

function prepareEdit(id, name) {
    document.getElementById("modalTitle").textContent = "Edit Brand";
    document.getElementById("brandId").value = id;
    document.getElementById("brandName").value = name;
}
</script>

</body>
</html>
