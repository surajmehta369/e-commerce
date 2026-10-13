<?php
require_once "auth.php";
require_once "../connection/dbconnect.php";

$database = new Database();
$db = $database->connect();

$message = "";
$messageType = "success";
function createSlug($name)
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
            $parentId = ($_POST["parent_id"] ?? "") !== ""
                ? (int)$_POST["parent_id"]
                : null;

            if ($name === "" || strlen($name) > 100) {
                throw new Exception("Enter a category name (maximum 100 characters).");
            }

            if ($parentId !== null && $parentId <= 0) {
                $parentId = null;
            }
            if ($id > 0 && $parentId === $id) {
                throw new Exception("A category cannot be its own parent.");
            }
            if ($parentId !== null) {
                $stmt = $db->prepare(
                    "SELECT id FROM categories WHERE id = ? AND status = 1"
                );
                $stmt->execute([$parentId]);

                if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                    throw new Exception("Selected parent category is not available.");
                }
            }
            if ($id > 0 && $parentId !== null) {
                $checkParent = $parentId;

                while ($checkParent !== null) {
                    if ($checkParent === $id) {
                        throw new Exception(
                            "This parent would create a category hierarchy loop."
                        );
                    }

                    $stmt = $db->prepare(
                        "SELECT parent_id FROM categories WHERE id = ?"
                    );
                    $stmt->execute([$checkParent]);
                    $parentRow = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$parentRow) {
                        break;
                    }

                    $checkParent = $parentRow["parent_id"] !== null
                        ? (int)$parentRow["parent_id"]
                        : null;
                }
            }

            $slug = createSlug($name);

            if ($slug === "") {
                throw new Exception("Please enter a name containing letters or numbers.");
            }
            $baseSlug = $slug;
            $counter = 1;

            while (true) {
                $sql = "SELECT id FROM categories WHERE slug = ?";
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
                    "UPDATE categories
                     SET name = ?, slug = ?, parent_id = ?
                     WHERE id = ?"
                );
                $stmt->execute([$name, $slug, $parentId, $id]);
                $message = "Category updated successfully.";
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO categories (name, slug, parent_id, status)
                     VALUES (?, ?, ?, 1)"
                );
                $stmt->execute([$name, $slug, $parentId]);
                $message = "Category added successfully.";
            }
        } elseif ($action === "toggle") {
            $id = (int)($_POST["id"] ?? 0);

            $stmt = $db->prepare(
                "SELECT status FROM categories WHERE id = ?"
            );
            $stmt->execute([$id]);
            $category = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$category) {
                throw new Exception("Category not found.");
            }

            $newStatus = (int)$category["status"] === 1 ? 0 : 1;

            // Do not deactivate a category while its products or
            // subcategories are still active/assigned.
            if ($newStatus === 0) {
                $stmt = $db->prepare(
                    "SELECT COUNT(*) FROM products WHERE category_id = ?"
                );
                $stmt->execute([$id]);

                if ((int)$stmt->fetchColumn() > 0) {
                    throw new Exception(
                        "This category has products assigned to it. Reassign those products before deactivating it."
                    );
                }

                $stmt = $db->prepare(
                    "SELECT COUNT(*) FROM categories
                     WHERE parent_id = ? AND status = 1"
                );
                $stmt->execute([$id]);

                if ((int)$stmt->fetchColumn() > 0) {
                    throw new Exception(
                        "Deactivate or reassign its active subcategories first."
                    );
                }
            }

            $stmt = $db->prepare(
                "UPDATE categories SET status = ? WHERE id = ?"
            );
            $stmt->execute([$newStatus, $id]);

            $message = $newStatus
                ? "Category activated successfully."
                : "Category deactivated successfully.";
        } elseif ($action === "delete") {
            $id = (int)($_POST["id"] ?? 0);

            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM products WHERE category_id = ?"
            );
            $stmt->execute([$id]);
            $productCount = (int)$stmt->fetchColumn();

            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM categories WHERE parent_id = ?"
            );
            $stmt->execute([$id]);
            $childCount = (int)$stmt->fetchColumn();

            if ($productCount > 0 || $childCount > 0) {
                throw new Exception(
                    "Cannot delete this category because products or subcategories are linked to it."
                );
            }

            $stmt = $db->prepare("DELETE FROM categories WHERE id = ?");
            $stmt->execute([$id]);

            $message = "Category deleted successfully.";
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = "danger";
    }

    // Keep feedback visible after redirect
    session_start_if_needed:
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION["category_message"] = $message;
    $_SESSION["category_message_type"] = $messageType;

    header("Location: categories.php");
    exit;
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION["category_message"])) {
    $message = $_SESSION["category_message"];
    $messageType = $_SESSION["category_message_type"] ?? "success";
    unset($_SESSION["category_message"], $_SESSION["category_message_type"]);
}

$search = trim($_GET["search"] ?? "");

$sql = "
    SELECT
        c.id,
        c.name,
        c.slug,
        c.parent_id,
        c.status,
        c.created_at,
        p.name AS parent_name,
        (SELECT COUNT(*) FROM products pr
         WHERE pr.category_id = c.id) AS product_count
    FROM categories c
    LEFT JOIN categories p ON p.id = c.parent_id
";

$params = [];

if ($search !== "") {
    $sql .= " WHERE c.name LIKE ? OR c.slug LIKE ?";
    $params = ["%" . $search . "%", "%" . $search . "%"];
}

$sql .= " ORDER BY COALESCE(c.parent_id, c.id), c.parent_id IS NOT NULL, c.name";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $db->query(
    "SELECT id, name FROM categories WHERE status = 1 ORDER BY name"
);
$parentCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalCategories = (int)$db->query(
    "SELECT COUNT(*) FROM categories"
)->fetchColumn();

$activeCategories = (int)$db->query(
    "SELECT COUNT(*) FROM categories WHERE status = 1"
)->fetchColumn();

$inactiveCategories = $totalCategories - $activeCategories;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories | Admin Panel</title>

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

        .category-child {
            padding-left: 25px;
        }

        .category-child::before {
            content: "↳ ";
            color: #64748b;
        }

        .slug {
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
        <a href="categories.php" class="active">Categories</a>
        <a href="brands.php">Brands</a>
        <a href="orders.php">Orders</a>
        <a href="profile.php">Admin Profile</a>
        <a href="../outh/logout.php">Logout</a>
    </aside>

    <main class="main">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h2 class="fw-bold mb-1">Categories</h2>
                <p class="text-secondary mb-0">
                    Manage product categories and subcategories.
                </p>
            </div>

            <button class="btn btn-primary" data-bs-toggle="modal"
                data-bs-target="#categoryModal" onclick="prepareAdd()">
                + Add Category
            </button>
        </div>

        <?php if ($message !== ""): ?>
            <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="text-secondary">Total Categories</div>
                    <div class="stat-number"><?= $totalCategories ?></div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="stat-card">
                    <div class="text-secondary">Active Categories</div>
                    <div class="stat-number text-success"><?= $activeCategories ?></div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="stat-card">
                    <div class="text-secondary">Inactive Categories</div>
                    <div class="stat-number text-danger"><?= $inactiveCategories ?></div>
                </div>
            </div>
        </div>

        <section class="content-card">
            <form method="GET" class="row g-2 mb-4">
                <div class="col-md-5">
                    <input type="text" name="search" class="form-control"
                        placeholder="Search category name or slug..."
                        value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-auto">
                    <button class="btn btn-dark">Search</button>
                    <a href="categories.php" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>S.No.</th>
                            <th>Category</th>
                            <th>Slug</th>
                            <th>Parent Category</th>
                            <th>Products</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (count($categories) > 0): ?>
                            <?php $serialNumber = 1; ?>
                            <?php foreach ($categories as $category): ?>
                                <tr>
                                    <td><?= $serialNumber++ ?></td>

                                    <td>
                                        <?= htmlspecialchars($category["name"]) ?>
                                    </td>

                                    <td><span class="slug"><?= htmlspecialchars($category["slug"]) ?></span></td>

                                    <td>
                                        <?= htmlspecialchars($category["parent_name"] ?? "— Main Category —") ?>
                                    </td>

                                    <td><?= (int)$category["product_count"] ?></td>

                                    <td>
                                        <?php if ((int)$category["status"] === 1): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>

                                    <td><?= htmlspecialchars(date("d M Y", strtotime($category["created_at"]))) ?></td>

                                    <td>
                                        <div class="d-flex flex-wrap gap-1">
                                            <button type="button"
                                                class="btn btn-sm btn-outline-primary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#categoryModal"
                                                onclick='prepareEdit(
                                                <?= (int)$category["id"] ?>,
                                                <?= json_encode($category["name"]) ?>,
                                                <?= $category["parent_id"] === null ? "null" : (int)$category["parent_id"] ?>
                                            )'>
                                                Edit
                                            </button>

                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="id" value="<?= (int)$category["id"] ?>">
                                                <button class="btn btn-sm btn-outline-warning"
                                                    onclick="return confirm('Change this category status?')">
                                                    <?= (int)$category["status"] === 1 ? "Deactivate" : "Activate" ?>
                                                </button>
                                            </form>

                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$category["id"] ?>">
                                                <button class="btn btn-sm btn-outline-danger"
                                                    onclick="return confirm('Are you sure you want to delete this category?')">
                                                    Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center text-secondary py-4">
                                    No categories found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <!-- Add/Edit Category Modal -->
    <div class="modal fade" id="categoryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content border-0 rounded-4">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalTitle">Add Category</h5>
                        <button type="button" class="btn-close"
                            data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" id="categoryId" value="0">

                        <div class="mb-3">
                            <label class="form-label">Category Name</label>
                            <input type="text" name="name" id="categoryName"
                                class="form-control" maxlength="100" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Parent Category</label>
                            <select name="parent_id" id="parentId" class="form-select">
                                <option value="">None — Main Category</option>

                                <?php foreach ($parentCategories as $parent): ?>
                                    <option value="<?= (int)$parent["id"] ?>">
                                        <?= htmlspecialchars($parent["name"]) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <small class="text-secondary">
                                Leave this empty to create a main category.
                            </small>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-light"
                            data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        function prepareAdd() {
            document.getElementById("modalTitle").textContent = "Add Category";
            document.getElementById("categoryId").value = "0";
            document.getElementById("categoryName").value = "";
            document.getElementById("parentId").value = "";
        }

        function prepareEdit(id, name, parentId) {
            document.getElementById("modalTitle").textContent = "Edit Category";
            document.getElementById("categoryId").value = id;
            document.getElementById("categoryName").value = name;
            document.getElementById("parentId").value =
                parentId === null ? "" : String(parentId);

            // A category cannot be its own parent
            const ownOption = document.querySelector(
                '#parentId option[value="' + id + '"]'
            );

            if (ownOption) {
                ownOption.disabled = true;
            }
        }

        // Restore the own-category option when opening another edit form
        document.getElementById("categoryModal").addEventListener("hidden.bs.modal", function() {
            document.querySelectorAll("#parentId option").forEach(function(option) {
                option.disabled = false;
            });
        });
    </script>
</body>

</html>