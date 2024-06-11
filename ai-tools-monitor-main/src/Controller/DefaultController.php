<?php

namespace App\Controller;

use DateTime;
use DateTimeZone;
use Exception;
use PDOException;
use PDOStatement;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use PDO;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

class DefaultController extends AbstractController
{
    protected SluggerInterface $slugger;

    public function __construct(SluggerInterface $slugger)
    {
        $this->slugger = $slugger;
    }


    /**
     * Récupérer la liste des outils.
     *
     * @return Response La réponse HTTP avec la liste des outils.
     */
    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('home.html.twig', [
            'title' => 'Accueil'
        ]);
    }


    /**
     * Exécute une requête SQL passée en paramètre.
     *
     * @param string $request La requête SQL à exécuter.
     *
     * @throws PDOException Si l'exécution de la requête échoue.
     */
    protected function update(string $request): void
    {
        try {
            $sth = $this->getPDOStatement($request);
            $sth->execute();
        } catch (PDOException $e) {
            echo "Erreur: " . $e->getMessage();
            throw new PDOException($e);
        }
    }


    /**
     * Prépare une requête SQL pour exécution.
     *
     * @param string $request La requête SQL à préparer.
     *
     * @return bool|PDOStatement Retourne l'objet PDOStatement préparé ou false en cas d'erreur.
     */
    protected function getPDOStatement(string $request): bool|PDOStatement
    {
        $servname = $_ENV['SERVER'];
        $dbname = $_ENV['DB'];
        $user = $_ENV['USER'];
        $pass = $_ENV['PASSWORD'];
        $dbco = new PDO("mysql:host=$servname;dbname=$dbname", $user, $pass);
        $dbco->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $dbco->prepare($request);
    }


    /**
     * Exécute une requête d'insertion SQL passée en paramètre.
     *
     * @param string $request La requête SQL à exécuter.
     *
     * @throws PDOException Si l'exécution de la requête échoue.
     */
    protected function insert(string $request): void
    {
        try {
            $sth = $this->getPDOStatement($request);
            $sth->execute();
        } catch (PDOException $e) {
            echo "Erreur : " . $e->getMessage();
            throw new PDOException($e);
        }
    }


    /**
     * Exécute une requête de suppression SQL passée en paramètre.
     *
     * @param string $request La requête SQL à exécuter.
     *
     * @throws PDOException Si l'exécution de la requête échoue.
     */
    protected function delete(string $request): void
    {
        try {
            $sth = $this->getPDOStatement($request);
            $sth->execute();
        } catch (PDOException $e) {
            echo "Erreur : " . $e->getMessage();
            throw new PDOException($e);
        }
    }


    /**
     * Télécharger les données d'une table spécifique au format CSV ou JSON.
     *
     * @param Request $request La requête contenant les informations sur le type de fichier et la liste des ID.
     * @param string $table Le nom de la table dont les données doivent être téléchargées.
     * @param string $type Le type d'entité pour la condition WHERE.
     *
     * @return Response La réponse contenant le fichier à télécharger.
     *
     * @throws Exception Si une erreur se produit lors du traitement des données ou de la génération du fichier.
     */
    protected function download_data(Request $request, string $table, string $type): Response
    {
        $this->deleteOldFiles("files", 10);
        $data = json_decode($request->getContent(), true);
        $typeFile = $data['type'];
        $listId = $data['listId'];
        $timezone = new DateTimeZone('Europe/Paris');
        $dateTime = new DateTime('now', $timezone);
        $filename = "{$table}-" . $dateTime->format("Y_m_d-H_i_s") . "." . $typeFile;
        $filepath = "files/" . $filename;
        $where = "";
        foreach ($listId as $id) {
            $where .= " id_{$type} = " . $id . " OR";
        }
        $where = rtrim($where, " OR");
        $data = $this->select("SELECT * FROM {$table} WHERE " . $where);
        if ($typeFile === "csv") {
            $encodeData = $this->arrayToCSV($data);
        } else {
            $encodeData = $this->arrayToJSON($data);
        }
        $this->writeFile($filepath, $encodeData);
        $response = new BinaryFileResponse($filepath);
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        );
        return $response;
    }


    /**
     * Supprimer les fichiers dans un répertoire si la taille totale du répertoire dépasse une limite spécifiée.
     *
     * @param string $directoryPath Le chemin du répertoire à vérifier.
     * @param int $size La taille maximale en mégaoctets (MB) que le répertoire peut atteindre avant que les fichiers soient supprimés.
     */
    protected function deleteOldFiles($directoryPath, $size): void
    {
        if (!is_dir($directoryPath)) {
            return;
        }
        $dir = opendir($directoryPath);
        if ($this->getDirectorySizeInMB($directoryPath) > $size) {
            while (($file = readdir($dir)) !== false) {
                $filePath = $directoryPath . DIRECTORY_SEPARATOR . $file;
                if (is_file($filePath)) {
                    unlink($filePath);
                } elseif (is_dir($filePath) && $file !== '.' && $file !== '..') {
                    $this->deleteDirectory($filePath);
                }
            }
            closedir($dir);
        }
    }


    /**
     * Calculer la taille totale d'un répertoire en mégaoctets (MB).
     *
     * @param string $directoryPath Le chemin du répertoire dont la taille doit être calculée.
     *
     * @return float La taille totale du répertoire en mégaoctets (MB).
     */
    protected function getDirectorySizeInMB($directoryPath): float
    {
        $size = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directoryPath, \RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $size += $file->getSize();
        }
        return $size / (1024 * 1024);
    }


    /**
     * Supprimer un répertoire et tout son contenu.
     *
     * @param string $directoryPath Le chemin du répertoire à supprimer.
     */
    protected function deleteDirectory($directoryPath): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directoryPath)
        );

        foreach ($files as $fileinfo) {
            if ($fileinfo->isFile()) {
                unlink($fileinfo->getRealPath());
            } elseif ($fileinfo->isDir() && iterator_count(new \FilesystemIterator($fileinfo->getRealPath())) === 0) {
                rmdir($fileinfo->getRealPath());
            }
        }
        rmdir($directoryPath);
    }


    /**
     * Exécute une requête SQL SELECT et retourne les résultats sous forme de tableau.
     *
     * @param string $request La requête SQL à exécuter.
     *
     * @return bool|array Retourne un tableau des résultats ou false en cas d'erreur.
     *
     * @throws PDOException Si l'exécution de la requête échoue.
     */
    protected function select(string $request): bool|array
    {
        try {
            $sth = $this->getPDOStatement($request);
            $sth->execute();
            return $sth->fetchAll();
        } catch (PDOException $e) {
            echo "Erreur : " . $e->getMessage();
            throw new PDOException($e);
        }
    }


    /**
     * Convertir un tableau de données en format CSV.
     *
     * @param array $data Les données à convertir en CSV.
     *
     * @return string Les données formatées en CSV.
     */
    protected function arrayToCSV($data): string
    {
        $json = $this->arrayToJSON($data);
        $headers = $this->getCSVHeadersFromJSON($json);
        $decodeJSON = json_decode($json, true);
        $body = "";
        foreach ($decodeJSON as $log) {
            $ligne = "";
            foreach ($log as $col) {
                if (is_array($col)) {
                    $ligne = $ligne . json_encode($col) . ";";
                } else {
                    $ligne = $ligne . $col . ";";
                }
            }
            $body = $body . $ligne . "\n";
        }
        return $headers . "\n" . $body;
    }


    /**
     * Convertir un tableau de données en format JSON.
     *
     * @param array $data Les données à convertir en JSON.
     *
     * @return bool|string Les données formatées en JSON ou false en cas d'erreur.
     */
    protected function arrayToJSON($data): bool|string
    {
        foreach ($data as &$row) {
            foreach ($row as $key => $value) {
                if ($value !== null && $this->isJson($value)) {
                    $row[$key] = json_decode($value, true);
                }
            }
            $row = array_filter($row, function ($key) {
                return !is_numeric($key);
            }, ARRAY_FILTER_USE_KEY);
        }
        return json_encode($data);
    }


    /**
     * Vérifier si une chaîne est au format JSON valide.
     *
     * @param string $string La chaîne à vérifier.
     *
     * @return bool Retourne true si la chaîne est au format JSON valide, sinon false.
     */
    protected function isJson(string $string): bool
    {
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }


    /**
     * Extraire les en-têtes d'un fichier CSV à partir d'une chaîne JSON.
     *
     * @param string $json La chaîne JSON contenant les données.
     *
     * @return string Les en-têtes CSV séparés par des points-virgules, ou une chaîne vide si aucune donnée n'est présente.
     */
    protected function getCSVHeadersFromJSON($json): string
    {
        $headers = [];
        $data = json_decode($json, true);
        if (!empty($data)) {
            $firstEntry = reset($data);
            if (is_array($firstEntry)) {
                $headers = array_keys($firstEntry);
            }
        }
        return implode(';', $headers);
    }


    /**
     * Écrire le contenu spécifié dans un fichier.
     *
     * @param string $filename Le nom du fichier dans lequel écrire.
     * @param string $content Le contenu à écrire dans le fichier.
     *
     * @throws Exception Si l'ouverture ou l'écriture dans le fichier échoue.
     */
    protected function writeFile($filename, $content): void
    {
        $file = fopen($filename, "w");
        fwrite($file, $content);
        fclose($file);
    }


    /**
     * Effectue une requête cURL vers une URL avec les options spécifiées.
     *
     * @param string $to L'URL vers laquelle effectuer la requête.
     * @param array $options Les options de la requête cURL.
     *
     * @return string Le contenu de la réponse de la requête.
     *
     * @throws Exception Si l'URL n'est pas spécifiée, si la requête échoue ou si une erreur est retournée dans la réponse.
     */
    protected function curlRequest($to, $options): string
    {
        if ($to === null || $to === "") {
            throw new Exception("<error>L'URL n'as pas été renseigné !</>\n");
        }
        $curl = curl_init($to);
        foreach ($options as $option) {
            curl_setopt($curl, $option[0], $option[1]);
        }
        $response = curl_exec($curl);
        if ($response === false || isset(json_decode($response)->error)) {
            $error = curl_error($curl);
            curl_close($curl);
            if ($response === false) {
                throw new Exception($error);
            } else {
                throw new Exception(json_encode($response, JSON_PRETTY_PRINT));
            }
        }
        curl_close($curl);
        return $response;
    }


    /**
     * Insérer des données dans la base de données en exécutant une requête SQL préparée avec des paramètres liés.
     *
     * @param string $query La requête SQL d'insertion préparée.
     * @param array $params Les valeurs des paramètres à lier dans la requête.
     *
     * @throws PDOException Si l'exécution de la requête échoue.
     */
    protected function insertBindParams(string $query, array $params): void
    {
        try {
            $sth = $this->getPDOStatement($query);
            $sth->execute($params);
        } catch (PDOException $e) {
            echo "Erreur : " . $e->getMessage();
            throw new PDOException($e);
        }
    }


    /**
     * Mettre à jour des données dans la base de données en exécutant une requête SQL préparée avec des paramètres liés.
     *
     * @param string $query La requête SQL de mise à jour préparée.
     * @param array $params Les valeurs des paramètres à lier dans la requête.
     *
     * @throws PDOException Si l'exécution de la requête échoue.
     */
    protected function updateBindParams(string $query, array $params): void
    {
        try {
            $sth = $this->getPDOStatement($query);
            $sth->execute($params);
        } catch (PDOException $e) {
            echo "Erreur : " . $e->getMessage();
            throw new PDOException($e);
        }
    }


    /**
     * Créer un fichier CSV à partir d'un tableau de chaînes CSV.
     *
     * @param array $csvStrings Les chaînes CSV à utiliser pour créer le fichier CSV.
     *
     * @return string Le contenu du fichier CSV créé.
     */
    protected function createCSV(array $csvStrings): string
    {
        $data = [];
        $headers = [];
        $headerSet = false;

        foreach ($csvStrings as $csvString) {
            $rows = str_getcsv($csvString, "\n");
            foreach ($rows as $index => $row) {
                $columns = str_getcsv($row);
                if ($index === 0 && !$headerSet) {
                    $headers = $columns;
                    $headerSet = true;
                    continue;
                }
                $data[] = $columns;
            }
        }

        $csvOutput = implode(';', $headers) . "\n";
        foreach ($data as $row) {
            $csvOutput .= implode(';', $row) . "\n";
        }

        return $csvOutput;
    }


    /**
     * Créer une seule chaîne JSON à partir d'un tableau de chaînes JSON.
     *
     * @param array $jsonStrings Les chaînes JSON à fusionner.
     *
     * @return string La chaîne JSON résultante.
     *
     * @throws Exception Si une chaîne JSON invalide est fournie.
     */
    protected function createJSON(array $jsonStrings): string
    {
        $mergedArray = [];
        foreach ($jsonStrings as $jsonString) {
            $decoded = json_decode($jsonString, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $mergedArray = array_merge($mergedArray, $decoded);
            } else {
                throw new Exception("Invalid JSON string provided.");
            }
        }
        return json_encode($mergedArray);
    }
}