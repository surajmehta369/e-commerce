<?php
require_once __DIR__ . "/includes/layout.php";
require_once "../connection/dbconnect.php";

$database = new Database();
$db = $database->connect();

$message = "";
$messageType = "success";

/* Generate Category Slug */
function createSlug($name)
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-');
}

/* Escape Output */
function categoryEscape($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/* Handle POST Actions */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    try {

        /* ADD / EDIT CATEGORY */
        if ($action === "save") {

            $id = (int)($_POST["id"] ?? 0);
            $name = trim($_POST["name"] ?? "");

            $parentId = ($_POST["parent_id"] ?? "") !== ""
                ? (int)$_POST["parent_id"]
                : null;

            if ($name === "" || strlen($name) > 100) {
                throw new Exception(
                    "Enter a category name (maximum 100 characters)."
                );
            }

            if ($parentId !== null && $parentId <= 0) {
                $parentId = null;
            }

            if ($id > 0 && $parentId === $id) {
                throw new Exception(
                    "A category cannot be its own parent."
                );
            }

            /* Validate Parent Category */
            if ($parentId !== null) {

                $stmt = $db->prepare(
                    "SELECT id FROM categories
                     WHERE id = ? AND status = 1"
                );

                $stmt->execute([$parentId]);

                if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                    throw new Exception(
                        "Selected parent category is not available."
                    );
                }
            }

            /* Prevent Category Hierarchy Loop */
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

            /* Generate Unique Slug */
            $slug = createSlug($name);

            if ($slug === "") {
                throw new Exception(
                    "Please enter a name containing letters or numbers."
                );
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

            /* Update Existing Category */
            if ($id > 0) {

                $stmt = $db->prepare(
                    "UPDATE categories
                     SET name = ?, slug = ?, parent_id = ?
                     WHERE id = ?"
                );

                $stmt->execute([
                    $name,
                    $slug,
                    $parentId,
                    $id
                ]);

                $message = "Category updated successfully.";
            } else {

                /* Insert New Category */
                $stmt = $db->prepare(
                    "INSERT INTO categories
                     (name, slug, parent_id, status)
                     VALUES (?, ?, ?, 1)"
                );

                $stmt->execute([
                    $name,
                    $slug,
                    $parentId
                ]);

                $message = "Category added successfully.";
            }
        }

        /* ACTIVATE / DEACTIVATE CATEGORY */ elseif ($action === "toggle") {

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

            if ($newStatus === 0) {

                /* Check Assigned Products */
                $stmt = $db->prepare(
                    "SELECT COUNT(*) FROM products WHERE category_id = ?"
                );

                $stmt->execute([$id]);

                if ((int)$stmt->fetchColumn() > 0) {
                    throw new Exception(
                        "This category has products assigned to it. Reassign those products before deactivating it."
                    );
                }

                /* Check Active Subcategories */
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

            $stmt->execute([
                $newStatus,
                $id
            ]);

            $message = $newStatus
                ? "Category activated successfully."
                : "Category deactivated successfully.";
        }

        /* DELETE CATEGORY */ elseif ($action === "delete") {

            $id = (int)($_POST["id"] ?? 0);

            /* Check Products */
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM products WHERE category_id = ?"
            );

            $stmt->execute([$id]);

            $productCount = (int)$stmt->fetchColumn();

            /* Check Subcategories */
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

            $stmt = $db->prepare(
                "DELETE FROM categories WHERE id = ?"
            );

            $stmt->execute([$id]);

            $message = "Category deleted successfully.";
        }
    } catch (Exception $e) {

        $message = $e->getMessage();
        $messageType = "danger";
    }

    /* Store Message */
    $_SESSION["category_message"] = $message;
    $_SESSION["category_message_type"] = $messageType;

    header("Location: categories.php");
    exit;
}

/* Retrieve Session Message */
if (isset($_SESSION["category_message"])) {

    $message = $_SESSION["category_message"];

    $messageType = $_SESSION["category_message_type"] ?? "success";

    unset(
        $_SESSION["category_message"],
        $_SESSION["category_message_type"]
    );
}
/* SEARCH */
$search = trim($_GET["search"] ?? "");

/* FETCH CATEGORIES */
$sql = "
    SELECT
        c.id,
        c.name,
        c.slug,
        c.parent_id,
        c.status,
        c.created_at,
        p.name AS parent_name,

        (
            SELECT COUNT(*)
            FROM products pr
            WHERE pr.category_id = c.id
        ) AS product_count

    FROM categories c

    LEFT JOIN categories p
        ON p.id = c.parent_id
";

$params = [];

if ($search !== "") {

    $sql .= " WHERE c.name LIKE ? OR c.slug LIKE ?";

    $params = [
        "%" . $search . "%",
        "%" . $search . "%"
    ];
}

$sql .= "
    ORDER BY
    COALESCE(c.parent_id, c.id),
    c.parent_id IS NOT NULL,
    c.name
";

$stmt = $db->prepare($sql);
$stmt->execute($params);

$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* FETCH ACTIVE PARENT CATEGORIES */
$stmt = $db->query(
    "SELECT id, name
     FROM categories
     WHERE status = 1
     ORDER BY name"
);

$parentCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* DASHBOARD STATISTICS */
$totalCategories = (int)$db->query(
    "SELECT COUNT(*) FROM categories"
)->fetchColumn();

$activeCategories = (int)$db->query(
    "SELECT COUNT(*) FROM categories WHERE status = 1"
)->fetchColumn();

$inactiveCategories = $totalCategories - $activeCategories;

/* ADMIN HEADER */
adminHeader('Categories', 'categories');
?>

<style>
    .page-title {
        font-weight: 700;
        font-size: 28px;
    }

    .stat-card,
    .table-card {
        background: #fff;
        border: 0;
        border-radius: 14px;
        box-shadow: 0 3px 15px rgba(15, 23, 42, .05);
    }

    .stat-card {
        padding: 22px;
        height: 100%;
    }

    .stat-label {
        color: #6b7280;
        font-size: 14px;
        margin-bottom: 10px;
    }

    .stat-number {
        font-size: 27px;
        font-weight: 700;
    }

    .table-card {
        padding: 22px;
    }

    .table th {
        color: #64748b;
        font-size: 12px;
        text-transform: uppercase;
        white-space: nowrap;
        background: #f8fafc;
    }

    .table td {
        vertical-align: middle;
        font-size: 14px;
    }

    .badge {
        font-weight: 600;
        padding: 7px 9px;
    }

    .category-name {
        font-weight: 600;
        color: #111827;
    }

    .category-child {
        padding-left: 20px;
    }

    .category-child::before {
        content: "↳ ";
        color: #64748b;
    }

    .small-muted {
        color: #6b7280;
        font-size: 12px;
    }
</style>

<!-- PAGE HEADER -->

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

    <div>
        <h1 class="page-title mb-1">Categories</h1>

        <p class="text-secondary mb-0">
            Manage product categories and subcategories.
        </p>
    </div>

    <button
        type="button"
        class="btn btn-primary"
        data-bs-toggle="modal"
        data-bs-target="#categoryModal"
        onclick="prepareAdd()">

        + Add Category

    </button>

</div>

<!-- ALERT MESSAGE -->

<?php if ($message !== ""): ?>

    <div class="alert alert-<?= categoryEscape($messageType) ?> alert-dismissible fade show">

        <?= categoryEscape($message) ?>

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="alert">
        </button>

    </div>

<?php endif; ?>

<!-- STATISTICS -->

<div class="row g-3 mb-4">

    <div class="col-6 col-xl-4">

        <div class="stat-card">

            <div class="stat-label">
                Total Categories
            </div>

            <div class="stat-number">
                <?= $totalCategories ?>
            </div>

        </div>

    </div>

    <div class="col-6 col-xl-4">

        <div class="stat-card">

            <div class="stat-label">
                Active Categories
            </div>

            <div class="stat-number text-success">
                <?= $activeCategories ?>
            </div>

        </div>

    </div>

    <div class="col-6 col-xl-4">

        <div class="stat-card">

            <div class="stat-label">
                Inactive Categories
            </div>

            <div class="stat-number text-danger">
                <?= $inactiveCategories ?>
            </div>

        </div>

    </div>

</div>
<!-- CATEGORIES TABLE CARD -->

<div class="table-card">

    <!-- SEARCH FORM -->

    <form method="GET" class="row g-2 mb-4">

        <div class="col-12 col-md-5">

            <input
                type="text"
                name="search"
                class="form-control"
                placeholder="Search category name or slug..."
                value="<?= categoryEscape($search) ?>">

        </div>

        <div class="col-auto">

            <button
                type="submit"
                class="btn btn-dark">

                Search

            </button>

            <a
                href="categories.php"
                class="btn btn-outline-secondary">

                Reset

            </a>

        </div>

    </form>

    <!-- TABLE HEADER -->

    <div class="d-flex justify-content-between align-items-center mb-3">

        <h5 class="mb-0">
            All Categories
        </h5>

        <span class="text-secondary small">
            <?= count($categories) ?> categor(ies)
        </span>

    </div>

    <!-- CATEGORY TABLE -->

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

                <?php if (!empty($categories)): ?>

                    <?php $serialNumber = 1; ?>

                    <?php foreach ($categories as $category): ?>

                        <tr>

                            <!-- SERIAL NUMBER -->

                            <td>
                                <?= $serialNumber++ ?>
                            </td>

                            <!-- CATEGORY NAME -->

                            <td>

                                <div class="category-name">

                                    <?php if ($category["parent_id"] !== null): ?>

                                        <span class="category-child">
                                            <?= categoryEscape($category["name"]) ?>
                                        </span>

                                    <?php else: ?>

                                        <?= categoryEscape($category["name"]) ?>

                                    <?php endif; ?>

                                </div>

                            </td>

                            <!-- SLUG -->

                            <td>

                                <span class="small-muted">
                                    <?= categoryEscape($category["slug"]) ?>
                                </span>

                            </td>

                            <!-- PARENT CATEGORY -->

                            <td>

                                <?= categoryEscape(
                                    $category["parent_name"] ?? "— Main Category —"
                                ) ?>

                            </td>

                            <!-- PRODUCT COUNT -->

                            <td>

                                <strong>
                                    <?= (int)$category["product_count"] ?>
                                </strong>

                            </td>

                            <!-- STATUS -->

                            <td>

                                <?php if ((int)$category["status"] === 1): ?>

                                    <span class="badge bg-success">
                                        Active
                                    </span>

                                <?php else: ?>

                                    <span class="badge bg-secondary">
                                        Inactive
                                    </span>

                                <?php endif; ?>

                            </td>

                            <!-- CREATED DATE -->

                            <td>

                                <?= categoryEscape(
                                    date(
                                        "d M Y",
                                        strtotime($category["created_at"])
                                    )
                                ) ?>

                            </td>

                            <!-- ACTIONS -->

                            <td>

                                <div class="d-flex flex-wrap gap-1">

                                    <!-- EDIT -->

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#categoryModal"
                                        onclick='prepareEdit(
                                            <?= (int)$category["id"] ?>,
                                            <?= json_encode($category["name"]) ?>,
                                            <?= $category["parent_id"] === null
                                                ? "null"
                                                : (int)$category["parent_id"] ?>
                                        )'>

                                        Edit

                                    </button>

                                    <!-- ACTIVATE / DEACTIVATE -->

                                    <form method="POST" class="d-inline">

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="toggle">

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int)$category["id"] ?>">

                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-outline-warning"
                                            onclick="return confirm('Change this category status?')">

                                            <?= (int)$category["status"] === 1
                                                ? "Deactivate"
                                                : "Activate" ?>

                                        </button>

                                    </form>

                                    <!-- DELETE -->

                                    <form method="POST" class="d-inline">

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="delete">

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int)$category["id"] ?>">

                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-outline-danger"
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

                        <td
                            colspan="8"
                            class="text-center text-secondary py-5">

                            No categories found.

                        </td>

                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

<!-- ADD / EDIT CATEGORY MODAL -->

<div class="modal fade" id="categoryModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog">

        <div class="modal-content border-0 rounded-4">

            <form method="POST">

                <!-- MODAL HEADER -->

                <div class="modal-header">

                    <h5 class="modal-title" id="modalTitle">
                        Add Category
                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal">
                    </button>

                </div>

                <!-- MODAL BODY -->

                <div class="modal-body">

                    <input
                        type="hidden"
                        name="action"
                        value="save">

                    <input
                        type="hidden"
                        name="id"
                        id="categoryId"
                        value="0">

                    <!-- CATEGORY NAME -->

                    <div class="mb-3">

                        <label
                            for="categoryName"
                            class="form-label">

                            Category Name

                        </label>

                        <input
                            type="text"
                            name="name"
                            id="categoryName"
                            class="form-control"
                            maxlength="100"
                            required>

                    </div>

                    <!-- PARENT CATEGORY -->

                    <div class="mb-3">

                        <label
                            for="parentId"
                            class="form-label">

                            Parent Category

                        </label>

                        <select
                            name="parent_id"
                            id="parentId"
                            class="form-select">

                            <option value="">
                                None — Main Category
                            </option>

                            <?php foreach ($parentCategories as $parent): ?>

                                <option value="<?= (int)$parent["id"] ?>">

                                    <?= categoryEscape($parent["name"]) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                        <small class="text-secondary">

                            Leave this empty to create a main category.

                        </small>

                    </div>

                    <small class="text-secondary">

                        The category slug will be generated automatically.

                    </small>

                </div>

                <!-- MODAL FOOTER -->

                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-light"
                        data-bs-dismiss="modal">

                        Cancel

                    </button>

                    <button
                        type="submit"
                        class="btn btn-primary">

                        Save Category

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- JAVASCRIPT -->

<script>
    function prepareAdd() {

        document.getElementById("modalTitle").textContent = "Add Category";

        document.getElementById("categoryId").value = "0";

        document.getElementById("categoryName").value = "";

        document.getElementById("parentId").value = "";

        // Enable all parent options

        document.querySelectorAll("#parentId option").forEach(function(option) {
            option.disabled = false;
        });

    }

    function prepareEdit(id, name, parentId) {

        document.getElementById("modalTitle").textContent = "Edit Category";

        document.getElementById("categoryId").value = id;

        document.getElementById("categoryName").value = name;

        document.getElementById("parentId").value =
            parentId === null ? "" : String(parentId);

        // Enable all options first

        document.querySelectorAll("#parentId option").forEach(function(option) {
            option.disabled = false;
        });

        // A category cannot be its own parent

        const ownOption = document.querySelector(
            '#parentId option[value="' + id + '"]'
        );

        if (ownOption) {
            ownOption.disabled = true;
        }

    }

    // Reset disabled options when modal closes

    document.getElementById("categoryModal").addEventListener(
        "hidden.bs.modal",
        function() {

            document.querySelectorAll("#parentId option").forEach(function(option) {
                option.disabled = false;
            });

        }
    );
</script>

<?php adminFooter(); ?>