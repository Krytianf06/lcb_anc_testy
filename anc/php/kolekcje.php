<div class="w3-container w3-padding-64 w3-white" style="width:100%">
<div style="width:80%;margin:auto">

<form class="mb-8" method="POST">
  <div class="flex flex-wrap items-center gap-3">
    
    <input 
      name="szukaj"
      type="text"
      placeholder="Wpisz poszukiwaną frazę"
      value="<?php echo htmlspecialchars($_POST['szukaj'] ?? ''); ?>"
      class="w-full sm:w-[30%] px-3 py-2 border border-gray-300 rounded-lg 
             focus:outline-none focus:ring-2 focus:ring-gray-400 
             transition duration-200 hover:bg-gray-50"
    >

    <button 
      type="submit"
      class="px-4 py-2 bg-gray-200 border border-gray-300 rounded-full 
             hover:bg-gray-300 transition font-medium"
    >
      Szukaj
    </button>

    <?php if (isset($_POST["szukaj"]) && !empty($_POST["szukaj"])): ?>
      <button 
        type="submit" 
        name="wszystko"
		value="wszystko" 
        class="px-4 py-2 bg-gray-200 border border-gray-300 rounded-full 
               hover:bg-gray-300 transition font-medium"
      >
        Pokaż wszystko
      </button>
    <?php endif; ?>

  </div>
</form>

<style>
h2 {
	font-size:25px;
	padding-bottom:20px;
	border-bottom:1px solid black;
}
</style>

<?php
require_once dirname(__DIR__, 2) . '/config.php';

$pdo = getDB();

if ($_POST["wszystko"]=="wszystko") $_POST['szukaj']="";

$search = trim($_POST['szukaj'] ?? '');

// Buduj zapytanie wyszukiwania
$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "(KOLEKCJA LIKE ? OR ZBIOR LIKE ? OR ZAWARTOSC LIKE ?)";
    $params = array_fill(0, 3, "%$search%");
} 

$sql = "SELECT * FROM ZADANIA" . (!empty($where) ? " WHERE " . implode(' OR ', $where) : "");
$sql .= " ORDER BY IDKOLEKCJI, ZBIOR";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll();

if (empty($results)) {
    echo "<p style='text-align:center; padding:40px; color:#666;'>Brak wyników wyszukiwania.</p>";
} else {
    $currentCollection = '';
    
    foreach ($results as $row) {
        if ($row['KOLEKCJA'] !== $currentCollection) {
            if ($currentCollection !== '') {
                echo "</table>";
            }
            echo "<h2>" . htmlspecialchars($row['KOLEKCJA']) . "</h2>";
            echo "<table class='w3-table w3-striped' style='margin-bottom:100px'>";
            echo "<tr><th>KOLEKCJA</th><th>ZASOBY KOLEKCJI</th></tr>";
            $currentCollection = $row['KOLEKCJA'];
        }
        echo "<tr>";
        echo "<td style='width:50%'>" . htmlspecialchars($row['ZBIOR']) . "</td>";
        echo "<td>" . nl2br(htmlspecialchars($row['ZAWARTOSC'])) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}
?>

</div>
</div>
