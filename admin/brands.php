
<?php
require_once __DIR__ . "/includes/layout.php";
require_once "../connection/dbconnect.php";

$database = new Database();
$db = $database->connect();

$message = "";
$messageType = "success";

// Generate brand slug
function createBrandSlug($name)
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-');
}

// Escape output
function brandEscape($value)
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

// Handle POST requests
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    try {

        // ADD / EDIT BRAND
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

        // ACTIVATE / DEACTIVATE
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

        // DELETE BRAND
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

    $_SESSION["brand_message"] = $message;
    $_SESSION["brand_message_type"] = $messageType;

    header("Location: brands.php");
    exit;
}

// Flash messages
if (isset($_SESSION["brand_message"])) {

    $message = $_SESSION["brand_message"];

    $messageType = $_SESSION["brand_message_type"] ?? "success";

    unset($_SESSION["brand_message"]);
    unset($_SESSION["brand_message_type"]);
}

// Search
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

    $params = [
        "%" . $search . "%",
        "%" . $search . "%"
    ];
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

// Admin layout
adminHeader('Brands', 'brands');
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
        box-shadow: 0 3px 15px rgba(15,23,42,.05);
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

    .brand-name {
        font-weight: 600;
        color: #111827;
    }

    .small-muted {
        color: #6b7280;
        font-size: 12px;
    }

    .brand-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
    }

    .brand-actions form {
        margin: 0;
    }
</style>

<!-- PAGE HEADER -->

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

    <div>
        <h1 class="page-title mb-1">Brands</h1>

        <p class="text-secondary mb-0">
            Manage product brands in your store.
        </p>
    </div>

    <button
        type="button"
        class="btn btn-dark"
        data-bs-toggle="modal"
        data-bs-target="#brandModal"
        onclick="prepareAdd()">

        + Add Brand

    </button>

</div>

<!-- ALERT MESSAGE -->

<?php if ($message !== ''): ?>

    <div class="alert alert-<?= brandEscape($messageType) ?> alert-dismissible fade show">

        <?= brandEscape($message) ?>

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
            <div class="stat-label">Total Brands</div>

            <div class="stat-number">
                <?= $totalBrands ?>
            </div>
        </div>

    </div>

    <div class="col-6 col-xl-4">

        <div class="stat-card">
            <div class="stat-label">Active Brands</div>

            <div class="stat-number text-success">
                <?= $activeBrands ?>
            </div>
        </div>

    </div>

    <div class="col-6 col-xl-4">

        <div class="stat-card">
            <div class="stat-label">Inactive Brands</div>

            <div class="stat-number text-danger">
                <?= $inactiveBrands ?>
            </div>
        </div>

    </div>

</div>

<!-- BRAND TABLE -->

<div class="table-card">

    <!-- SEARCH FORM -->

    <form method="GET" class="row g-2 mb-4">

        <div class="col-12 col-lg-8">

            <input
                type="text"
                name="search"
                class="form-control"
                placeholder="Search brand name or slug..."
                value="<?= brandEscape($search) ?>">

        </div>

        <div class="col-6 col-md-2 col-lg-2 d-grid">

            <button type="submit" class="btn btn-dark">
                Search
            </button>

        </div>

        <div class="col-6 col-md-2 col-lg-2 d-grid">

            <a href="brands.php" class="btn btn-outline-secondary">
                Reset
            </a>

        </div>

    </form>

    <!-- BRANDS TABLE -->

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

                <?php if ($brands): ?>

                    <?php foreach ($brands as $index => $brand): ?>

                        <tr>

                            <td>
                                <?= $index + 1 ?>
                            </td>

                            <td>
                                <div class="brand-name">
                                    <?= brandEscape($brand["name"]) ?>
                                </div>
                            </td>

                            <td>
                                <span class="small-muted">
                                    <?= brandEscape($brand["slug"]) ?>
                                </span>
                            </td>

                            <td>
                                <strong>
                                    <?= (int)$brand["product_count"] ?>
                                </strong>
                            </td>

                            <td>

                                <?php if ((int)$brand["status"] === 1): ?>

                                    <span class="badge text-bg-success">
                                        Active
                                    </span>

                                <?php else: ?>

                                    <span class="badge text-bg-secondary">
                                        Inactive
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>

                                <?= !empty($brand["created_at"])
                                    ? brandEscape(date("d M Y", strtotime($brand["created_at"])))
                                    : '-' ?>

                            </td>

                            <td>

                                <div class="brand-actions">

                                    <!-- EDIT BUTTON -->

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#brandModal"
                                        onclick='prepareEdit(
                                            <?= (int)$brand["id"] ?>,
                                            <?= json_encode(
                                                $brand["name"],
                                                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                                            ) ?>
                                        )'>

                                        Edit

                                    </button>

                                    <!-- ACTIVATE / DEACTIVATE -->

                                    <form method="POST">

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="toggle">

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int)$brand["id"] ?>">

                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-outline-warning"
                                            onclick="return confirm('Change this brand status?')">

                                            <?= (int)$brand["status"] === 1
                                                ? "Deactivate"
                                                : "Activate" ?>

                                        </button>

                                    </form>

                                    <!-- DELETE BUTTON -->

                                    <form method="POST">

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="delete">

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int)$brand["id"] ?>">

                                        <button
                                            type="submit"
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
                            class="text-center text-secondary py-5">

                            No brands found.

                        </td>

                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

<!-- ADD / EDIT BRAND MODAL -->

<div
    class="modal fade"
    id="brandModal"
    tabindex="-1"
    aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content border-0 rounded-4">

            <form method="POST">

                <div class="modal-header">

                    <h5 class="modal-title" id="modalTitle">
                        Add Brand
                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal">
                    </button>

                </div>

                <div class="modal-body">

                    <input
                        type="hidden"
                        name="action"
                        value="save">

                    <input
                        type="hidden"
                        name="id"
                        id="brandId"
                        value="0">

                    <div class="mb-3">

                        <label for="brandName" class="form-label">
                            Brand Name
                        </label>

                        <input
                            type="text"
                            name="name"
                            id="brandName"
                            class="form-control"
                            maxlength="100"
                            placeholder="Enter brand name"
                            required>

                    </div>

                    <small class="text-secondary">
                        The brand slug will be generated automatically.
                    </small>

                </div>

                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        data-bs-dismiss="modal">

                        Cancel

                    </button>

                    <button
                        type="submit"
                        class="btn btn-dark">

                        Save Brand

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- JAVASCRIPT -->

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

<!-- ADMIN FOOTER -->

<?php adminFooter(); ?>
