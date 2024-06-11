<?php

namespace App\Controller;

use Exception;
use PDOException;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ToolsController extends DefaultController
{


    /**
     * Afficher la liste des outils.
     *
     * @return Response La réponse HTTP avec la liste des outils.
     */
    #[Route('/tool', name: 'list_tools', methods: ['GET'])]
    public function list_tools(): Response
    {
        $tools = $this->select("
SELECT Tools.*, COALESCE(SUM(nbErrorsPerLanguage), 0) AS nbErrors
FROM Tools
         LEFT JOIN (SELECT Languages.id_tool, COUNT(*) AS nbErrorsPerLanguage
                    FROM Languages
                             INNER JOIN Logs ON Languages.id_language = Logs.id_language
                    WHERE Logs.isError = TRUE
                      AND Logs.date_log >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    GROUP BY Languages.id_tool) AS ErrorsPerLanguage ON Tools.id_tool = ErrorsPerLanguage.id_tool
GROUP BY Tools.id_tool;
");
        $list_levels = $this->select("
SELECT L.id_level, name_level, level, id_tool
FROM Levels L
LEFT JOIN levels_tools lt on L.id_level = lt.id_level;
");
        $errorLevelColumns = '';
        foreach ($list_levels as $level) {
            $levelId = $level['id_level'];
            $errorLevelColumns .= "
            IFNULL(SUM(CASE WHEN L2.id_level = $levelId AND L2.isError = TRUE AND L2.date_log >= NOW() - INTERVAL 1 DAY THEN 1 ELSE 0 END), 0) AS nbErrors_level_$levelId,";
        }
        $errorLevelColumns = rtrim($errorLevelColumns, ',');
        $list_languages = $this->select("
SELECT L.*,
       $errorLevelColumns
FROM Languages L
LEFT JOIN Logs L2 on L.id_language = L2.id_language
GROUP BY L.id_language;
");
        $list_active_level = $this->select("
SELECT *
FROM active_level;
");
        return $this->render('Tools/list_tools.html.twig', [
            'title' => 'Liste des outils',
            'tools' => $tools,
            'list_languages' => $list_languages,
            'list_levels' => $list_levels,
            'list_active_level' => $list_active_level
        ]);
    }


    /**
     * Afficher les informations sur un outil spécifique.
     *
     * @param Request $request La requête HTTP.
     * @return Response La réponse HTTP avec les informations sur l'outil.
     */
    #[Route('/tool/{id}', name: 'one_tool', methods: ['GET'])]
    public function one_tool(Request $request): Response
    {
        $id_tool = $request->attributes->get('id');

        $informations = $this->select("
SELECT Tools.*, COALESCE(SUM(nbErrorsPerLanguage), 0) AS nbErrors
FROM Tools
LEFT JOIN (
    SELECT Languages.id_tool, COUNT(*) AS nbErrorsPerLanguage
    FROM Languages
    INNER JOIN Logs ON Languages.id_language = Logs.id_language
    WHERE Logs.isError = TRUE
    AND Logs.date_log >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY Languages.id_tool
) AS ErrorsPerLanguage ON Tools.id_tool = ErrorsPerLanguage.id_tool
WHERE Tools.id_tool = $id_tool;
");

        $levels = $this->select("
SELECT Levels.id_level,
       Levels.name_level,
       Levels.level
FROM Levels
JOIN levels_tools ON Levels.id_level = levels_tools.id_level
WHERE levels_tools.id_tool = $id_tool
ORDER BY level;
");

        $errorLevelColumns = '';
        $subQueries = '';
        foreach ($levels as $level) {
            $levelId = $level['id_level'];
            $errorLevelColumns .= "IFNULL(nbErrorsLevel$levelId.nbErrors_level_$levelId, 0) AS nbErrors_level_$levelId, ";
            $subQueries .= "
        LEFT JOIN (
            SELECT id_language, COUNT(id_log) AS nbErrors_level_$levelId
            FROM Logs
            WHERE isError = TRUE AND date_log >= NOW() - INTERVAL 1 DAY AND id_level = $levelId
            GROUP BY id_language
        ) AS nbErrorsLevel$levelId ON Languages.id_language = nbErrorsLevel$levelId.id_language
        ";
        }
        $errorLevelColumns = rtrim($errorLevelColumns, ', ');

        $languagesQuery = "
SELECT
    Languages.id_language,
    Languages.short_name_language,
    Languages.name_language,
    Languages.is_active,
    GROUP_CONCAT(DISTINCT Levels.id_level ORDER BY Levels.level ASC SEPARATOR ', ') AS active_levels,
    IFNULL(ErrorCounts.nbErrors, 0) AS nbErrors,
    GROUP_CONCAT(DISTINCT active_level.id_level) AS active_level_id,
    $errorLevelColumns
FROM
    Languages
    INNER JOIN Tools ON Languages.id_tool = Tools.id_tool
    LEFT JOIN active_level ON Languages.id_language = active_level.id_language
    LEFT JOIN Levels ON active_level.id_level = Levels.id_level
    LEFT JOIN (
        SELECT id_language, COUNT(id_log) AS nbErrors
        FROM Logs
        WHERE isError = TRUE AND date_log >= NOW() - INTERVAL 1 DAY
        GROUP BY id_language
    ) AS ErrorCounts ON Languages.id_language = ErrorCounts.id_language
    $subQueries
WHERE
    Tools.id_tool = $id_tool
GROUP BY
    Languages.id_language,
    Languages.short_name_language,
    Languages.name_language,
    Languages.is_active;
    ";
        $languages = $this->select($languagesQuery);

        return $this->render('Tools/tool.html.twig', [
            'title' => 'Informations sur un outil',
            'informations' => $informations[0],
            'languages' => $languages,
            'levels' => $levels
        ]);
    }


    /**
     * Récupérer tous les journaux pour une langue spécifique.
     *
     * @param Request $request La requête HTTP.
     * @return Response La réponse HTTP avec les journaux pour la langue spécifique.
     */
    #[Route('/tool/language/{id}', name: 'all_log_language', methods: ['GET'])]
    public function all_log_language(Request $request): Response
    {
        $id_language = $request->attributes->get('id');
        $informations = $this->select("
SELECT *
FROM Languages
JOIN Tools T on Languages.id_tool = T.id_tool
WHERE id_language = $id_language;
");
        $logs = $this->select("
SELECT id_log, date_log, L.id_level, name_level, level, isError, latency_log
FROM Logs
    JOIN Levels AS L on Logs.id_level = L.id_level
WHERE id_language = $id_language
ORDER BY date_log DESC;
");
        $levels = $this->select("
SELECT Levels.id_level, Levels.name_level, Levels.level,
       SUM(CASE WHEN L.isError = 1 AND L.date_log >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS nbErrors
FROM Levels
         JOIN Logs AS L ON Levels.id_level = L.id_level
WHERE L.id_language = $id_language
GROUP BY Levels.id_level;
");
        $nbLog = $this->select("
SELECT COUNT(*) AS nbLog
FROM Logs;
");
        return $this->render('Tools/one_language.html.twig', [
            'title' => 'Informations sur une langue',
            'informations' => $informations[0],
            'logs' => $logs,
            'levels' => $levels,
            'nbLog' => $nbLog[0]['nbLog']
        ]);
    }


    /**
     * Récupérer les informations sur un seul journal pour une langue spécifique.
     *
     * @param Request $request La requête HTTP.
     * @return Response La réponse HTTP avec les informations sur le journal spécifique.
     */
    #[Route('/tool/language/log/{id}', name: 'one_log_language', methods: ['GET'])]
    public function one_log_language(Request $request): Response
    {
        $id_log = $request->attributes->get('id');
        $info = $this->getInfosOneLog($id_log);
        return $this->render('Tools/one_log.html.twig', [
            'title' => 'Informations sur un log',
            'informations' => $info['informations'],
            'completion_tokens' => $info['completion_tokens'],
            'prompt_tokens' => $info['prompt_tokens'],
            'total_tokens' => $info['total_tokens'],
            'response_message' => $info['response_message'],
            'params_message' => $info['params_message']
        ]);
    }


    /**
     * Récupère les informations sur un seul journal pour une langue spécifique.
     *
     * @param int $id L'identifiant du journal.
     * @return array Les informations sur le journal spécifié.
     */
    private function getInfosOneLog(int $id): array
    {
        $informations = $this->select("
SELECT *
FROM Logs
    JOIN Languages AS L on Logs.id_language = L.id_language
    JOIN Levels AS L2 on Logs.id_level = L2.id_level
    JOIN Tools AS T on L.id_tool = T.id_tool
    LEFT JOIN Errors_Types ET on Logs.id_error_type = ET.id_error_type
WHERE id_log = $id;
");
        $months = [
            'January' => 'janvier',
            'February' => 'février',
            'March' => 'mars',
            'April' => 'avril',
            'May' => 'mai',
            'June' => 'juin',
            'July' => 'juillet',
            'August' => 'août',
            'September' => 'septembre',
            'October' => 'octobre',
            'November' => 'novembre',
            'December' => 'décembre',
        ];
        foreach ($informations as &$info) {
            $dateTime = date_create($info['date_log']);
            $datePart = str_replace(array_keys($months), array_values($months), date_format($dateTime, 'd F Y'));
            $timePart = date_format($dateTime, 'H:i:s');
            $info['date_log'] = $datePart . ' à ' . $timePart;
        }
        $jsonParams = $informations[0]['params_log'];
        $jsonResponse = $informations[0]['response_log'];
        $location = $this->select("
SELECT content_params
FROM Parameters
WHERE id_params = 1;
");
        $response_message = $this->getValueFromJson($jsonResponse, $location[0]['content_params']);
        $location = $this->select("
SELECT content_params
FROM Parameters
WHERE id_params = 2;
");
        $params_message = $this->getValueFromJson($jsonParams, $location[0]['content_params']);
        $location = $this->select("
SELECT content_params
FROM Parameters
WHERE id_params = 4;
");
        $completion_tokens = $this->getValueFromJson($jsonResponse, $location[0]['content_params']);
        $location = $this->select("
SELECT content_params
FROM Parameters
WHERE id_params = 5;
");
        $prompt_tokens = $this->getValueFromJson($jsonResponse, $location[0]['content_params']);
        $location = $this->select("
SELECT content_params
FROM Parameters
WHERE id_params = 6;
");
        $total_tokens = $this->getValueFromJson($jsonResponse, $location[0]['content_params']);
        return array(
            'informations' => $informations[0],
            'completion_tokens' => $completion_tokens,
            'prompt_tokens' => $prompt_tokens,
            'total_tokens' => $total_tokens,
            'response_message' => $response_message,
            'params_message' => $params_message
        );
    }


    /**
     * Obtient la valeur à partir d'une chaîne JSON en utilisant une localisation donnée.
     *
     * @param string $jsonString La chaîne JSON à partir de laquelle extraire la valeur.
     * @param string $location La localisation de la valeur à extraire, au format "key1.key2.key3".
     *
     * @return mixed|null La valeur extraite ou null si la localisation n'est pas valide.
     */
    private function getValueFromJson($jsonString, $location): mixed
    {
        $jsonData = json_decode($jsonString, true);
        $keys = explode('.', $location);
        $value = $jsonData;
        foreach ($keys as $key) {
            if (!isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }


    /**
     * Compare deux journaux pour une langue spécifique et récupère leurs informations.
     *
     * @param Request $request La requête contenant les identifiants des journaux à comparer.
     *
     * @return Response La réponse HTTP avec les informations des deux journaux comparés.
     */
    #[Route('/tool/language/log/compare/{id1}/{id2}', name: 'compare_log_language', methods: ['GET'])]
    public function compare_log_language(Request $request): Response
    {
        $log1 = $this->getInfosOneLog($request->attributes->get('id1'));
        $log2 = $this->getInfosOneLog($request->attributes->get('id2'));
        return $this->render('Tools/compare_log.html.twig', [
            'title' => 'Informations sur deux logs',
            'informations' => $log1['informations'],
            'log1' => $log1,
            'log2' => $log2
        ]);
    }


    /**
     * Désactive toutes les langues associées à un outil spécifique.
     *
     * @param Request $request La requête contenant l'identifiant de l'outil.
     *
     * @return JsonResponse La réponse JSON indiquant le statut de la modification.
     */
    #[Route('/tool/desable/all', name: 'disable_all_languages', methods: ['POST'])]
    public function disable_all_languages(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $id_tool = $data['id_tool'];
            $this->update("
UPDATE Languages
SET is_active = FALSE
WHERE id_tool = $id_tool;
");
            return new JsonResponse([
                'status' => 'success',
                'message' => 'Modification réussie !'
            ], Response::HTTP_OK);
        } catch (PDOException $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Echec de la modification : ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Active toutes les langues associées à un outil spécifique.
     *
     * @param Request $request La requête contenant l'identifiant de l'outil.
     *
     * @return JsonResponse La réponse JSON indiquant le statut de la modification.
     */
    #[Route('/tool/enable/all', name: 'enable_all_languages', methods: ['POST'])]
    public function enable_all_languages(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $id_tool = $data['id_tool'];
            $this->update("
UPDATE Languages
SET is_active = TRUE
WHERE id_tool = $id_tool;
");
            return new JsonResponse([
                'status' => 'success',
                'message' => 'Modification réussie !'
            ], Response::HTTP_OK);
        } catch (PDOException $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Echec de la modification : ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Modifie le niveau d'activation d'une langue pour un niveau spécifique.
     *
     * @param Request $request La requête contenant les données de modification.
     *
     * @return JsonResponse La réponse JSON indiquant le statut de la modification.
     */
    #[Route('/tool/change/level', name: 'change_level', methods: ['POST'])]
    public function change_level(Request $request): Response
    {
        try {
            $data = json_decode($request->getContent(), true);
            $id_language = $data['id_language'];
            $id_level = $data['id_level'];
            $status = $data['status'] ?? false;
            $to = $data['to'] ?? false;

            if ($status) {
                if ($to) {
                    $existing = $this->select("
SELECT COUNT(*) as count
FROM active_level
WHERE id_language = $id_language AND id_level = $id_level;
");
                    if ($existing[0]['count'] === 0) {
                        $this->insert("
INSERT INTO active_level (id_language, id_level)
VALUES ($id_language, $id_level);
");
                    }
                    $message = 'Niveau activé avec succès !';
                } else {
                    $this->delete("
DELETE FROM active_level
WHERE id_language = $id_language AND id_level = $id_level;
");
                    $message = 'Niveau désactivé avec succès !';
                }
            } else {
                $existing = $this->select("
SELECT COUNT(*) as count
FROM active_level
WHERE id_language = $id_language AND id_level = $id_level;
");
                if ($existing[0]['count'] > 0) {
                    $this->delete("
DELETE FROM active_level
WHERE id_language = $id_language AND id_level = $id_level;
");
                    $message = 'Niveau désactivé avec succès !';
                } else {
                    $this->insert("
INSERT INTO active_level (id_language, id_level)
VALUES ($id_language, $id_level);
");
                    $message = 'Niveau activé avec succès !';
                }
            }
            return new JsonResponse([
                'status' => 'success',
                'message' => $message
            ], Response::HTTP_OK);
        } catch (PDOException $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Echec de la modification : ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Modifie le statut d'activation d'une langue.
     *
     * @param Request $request La requête contenant les données de modification.
     *
     * @return JsonResponse La réponse JSON indiquant le statut de la modification.
     */
    #[Route('/tool/change/language', name: 'change_language', methods: ['POST'])]
    public function change_language(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $id_language = $data['id_language'];
            $status = $data['status'] ?? false;
            $to = $data['to'] ?? false;
            if ($status) {
                if ($to) {
                    $this->update("
UPDATE Languages
SET is_active = TRUE
WHERE id_language = $id_language;
");
                } else {
                    $this->update("
UPDATE Languages
SET is_active = FALSE
WHERE id_language = $id_language;
");
                }
            } else {
                $this->update("
UPDATE Languages
SET is_active = IF(is_active = TRUE, FALSE, TRUE)
WHERE id_language = $id_language;
");
            }
            return new JsonResponse([
                'status' => 'success',
                'message' => 'Modification réussie !'
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Echec de la modification : ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Supprime un journal de langue.
     *
     * @param Request $request La requête contenant les données de suppression.
     *
     * @return JsonResponse La réponse JSON indiquant le statut de la suppression.
     */
    #[Route('/tool/language/log/delete/one', name: 'delete_log', methods: ['POST'])]
    public function delete_log(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $id_log = $data['id_log'];
            $this->delete("
DELETE FROM Logs 
       WHERE id_log = $id_log;
");
            return new JsonResponse([
                'status' => 'success',
                'message' => 'Suppression réussie !'
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Échec de la suppression : ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Supprime une langue et tous ses journaux associés.
     *
     * @param Request $request La requête contenant les données de suppression.
     *
     * @return JsonResponse La réponse JSON indiquant le statut de la suppression.
     */
    #[Route('/tool/language/delete/one', name: 'delete_language', methods: ['POST'])]
    public function delete_language(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $id_language = $data['id_language'];
            $this->delete("
DELETE FROM Languages
       WHERE id_language = $id_language;
");
            $this->delete("
DELETE FROM Logs
       WHERE id_language = $id_language;
");
            return new JsonResponse([
                'status' => 'success',
                'message' => 'Suppression réussie !'
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Echec de la suppression : ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Supprime un outil, toutes ses langues associées et tous les journaux et niveaux correspondants.
     *
     * @param Request $request La requête contenant les données de suppression.
     *
     * @return JsonResponse La réponse JSON indiquant le statut de la suppression.
     */
    #[Route('/tool/delete/one', name: 'delete_tool', methods: ['POST'])]
    public function delete_tool(Request $request): Response
    {
        try {
            $data = json_decode($request->getContent(), true);
            $id_tool = $data['id_tool'];
            $list_languages = $this->select("
SELECT id_language
FROM Languages
WHERE id_tool = $id_tool;
");
            foreach ($list_languages as $language) {
                $id = $language['id_language'];
                $this->delete("
DELETE FROM Languages
       WHERE id_language = $id;
");
                $this->delete("
DELETE FROM Logs
       WHERE id_language = $id;
");
                $this->delete("
DELETE FROM active_level
       WHERE id_tool = $id;
");
            }
            $list_level = $this->select("
SELECT lt.id_level
FROM Levels
JOIN chatgpt.levels_tools lt on Levels.id_level = lt.id_level
WHERE id_tool = $id_tool
");
            $this->delete("
DELETE FROM levels_tools
       WHERE id_tool = $id_tool;
");
            foreach ($list_level as $level) {
                $id = $level['id_level'];
                $this->delete("
DELETE FROM levels
       WHERE id_level = $id;
");
            }
            $this->delete("
DELETE FROM Tools
       WHERE id_tool = $id_tool;
");
            return new JsonResponse([
                'status' => 'success',
                'message' => 'Suppression réussie !'
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Echec de la suppression : ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Ajouter un nouvel outil avec les informations fournies.
     *
     * @param Request $request La requête contenant les données de l'outil à ajouter.
     *
     * @return RedirectResponse Redirige vers la liste des outils après l'ajout.
     */
    #[Route('/tool/add/one', name: 'form_add_tool', methods: ['POST'])]
    public function form_add_tool(Request $request): RedirectResponse
    {
        $name = $request->request->get('name');
        $api_name = $request->request->get('api_name');

        $this->insert("
INSERT INTO Tools(name_tool, api_name_tool)
VALUES ('$name', '$api_name');
");

        $result = $this->select("
SELECT id_tool
FROM Tools
WHERE name_tool = '$name' AND api_name_tool = '$api_name'
ORDER BY id_tool DESC
LIMIT 1;
");
        $id_tool = $result[0]['id_tool'];
        $this->insert("
INSERT INTO Levels (name_level, level)
VALUES ('Defaut', 100);
");
        $result = $this->select("
SELECT id_level
FROM Levels
ORDER BY id_level DESC
LIMIT 1;
");
        $id_level = $result[0]['id_level'];
        $this->insert("
INSERT INTO levels_tools (id_tool, id_level)
VALUES ($id_tool, $id_level);
");

        return new RedirectResponse($this->generateUrl('list_tools'));
    }


    /**
     * Générer un nouveau nom de fichier pour l'image téléchargée.
     *
     * @param mixed $imageFile Le fichier image téléchargé.
     *
     * @return string Le nouveau nom de fichier généré.
     */
    private function getNewFilename(mixed $imageFile): string
    {
        $destination = $this->getParameter('kernel.project_dir') . '/public/images/images_tools';
        $originalFilename = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $this->slugger->slug($originalFilename);
        $newFilename = $safeFilename . '-' . uniqid() . '.' . $imageFile->guessExtension();
        try {
            $imageFile->move(
                $destination,
                $newFilename
            );
        } catch (FileException $e) {
            var_dump($e->getMessage());
        }
        return $newFilename;
    }


    /**
     * Ajouter une nouvelle langue pour un outil donné.
     *
     * @param Request $request La requête HTTP contenant les informations de la langue à ajouter.
     *
     * @return RedirectResponse Redirige vers la page d'informations sur l'outil une fois la langue ajoutée.
     */
    #[Route('/tool/add/language', name: 'form_add_language', methods: ['POST'])]
    public function form_add_language(Request $request): RedirectResponse
    {
        $id_tool = $request->request->get('id_tool');
        $full_name = $request->request->get('full_name');
        $short_name = $request->request->get('short_name');
        $this->insert("
INSERT INTO Languages(short_name_language, name_language, is_active, id_tool)
VALUES ('$short_name', '$full_name', FALSE, '$id_tool');
");
        return new RedirectResponse($this->generateUrl('one_tool', ['id' => $id_tool]));
    }


    /**
     * Met à jour les informations d'un outil existant.
     *
     * @param Request $request La requête HTTP contenant les nouvelles informations de l'outil.
     *
     * @return RedirectResponse Redirige vers la liste des outils une fois l'outil mis à jour.
     */
    #[Route('/tool/update/one', name: 'form_update_tool', methods: ['POST'])]
    public function form_update_tool(Request $request): RedirectResponse
    {
        $id_tool = $request->request->get('id_tool');
        $name = $request->request->get('name_update');
        $api_name = $request->request->get('api_name_update');
        $this->updatePrepare("
UPDATE Tools
SET name_tool = :name, api_name_tool = :api_name
WHERE id_tool = :id_tool;
", ['name' => $name, 'api_name' => $api_name, 'id_tool' => $id_tool]);
        return new RedirectResponse($this->generateUrl('list_tools'));
    }


    /**
     * Exécute une requête préparée de mise à jour avec des paramètres.
     *
     * @param string $request La requête SQL préparée.
     * @param array $params Les paramètres à utiliser dans la requête.
     *
     * @throws PDOException Si une erreur survient lors de l'exécution de la requête.
     */
    private function updatePrepare(string $request, array $params): void
    {
        try {
            $sth = $this->getPDOStatement($request);
            $sth->execute($params);
        } catch (PDOException $e) {
            echo "Erreur : " . $e->getMessage();
            throw new PDOException($e);
        }
    }


    /**
     * Exécute une requête préparée de mise à jour pour modifier les informations d'une langue.
     *
     * @param Request $request L'objet Request contenant les données de la requête.
     *
     * @return RedirectResponse Une réponse de redirection vers la page d'un outil spécifique.
     */
    #[Route('/tool/update/language', name: 'form_update_language', methods: ['POST'])]
    public function form_update_language(Request $request): RedirectResponse
    {
        $id_tool = $request->request->get('id_tool_update');
        $id_language = $request->request->get('id_language');
        $name = $request->request->get('name_update');
        $short_name = $request->request->get('short_name_update');
        $this->updatePrepare("
UPDATE Languages
SET name_language = :name, short_name_language = :short_name
WHERE id_language = :id_language;
", ['name' => $name, 'short_name' => $short_name, 'id_language' => $id_language]);
        return new RedirectResponse($this->generateUrl('one_tool', ['id' => $id_tool]));
    }


    /**
     * Ajoute un nouveau niveau pour un outil spécifique.
     *
     * @param Request $request L'objet Request contenant les données de la requête.
     *
     * @return RedirectResponse Une réponse de redirection vers la page d'un outil spécifique.
     */
    #[Route('/tool/level/add', name: 'form_add_level', methods: ['POST'])]
    public function form_add_level(Request $request): RedirectResponse
    {
        $id_tool = $request->request->get('id_tool');
        $name = $request->request->get('name');
        $level = $request->request->get('level');
        $this->insert("
INSERT INTO levels(name_level, level)
VALUES ('$name', $level);
");
        $id_level = $this->select("
SELECT MAX(id_level) AS id_level
FROM Levels;
            ");
        $id_level = $id_level[0]['id_level'];
        $this->insert("
INSERT INTO levels_tools(id_tool, id_level)
VALUES ($id_tool, $id_level);
");
        return new RedirectResponse($this->generateUrl('one_tool', ['id' => $id_tool]));
    }


    /**
     * Supprime un niveau spécifique, ainsi que toutes les références à ce niveau dans les outils et les journaux.
     *
     * @param Request $request L'objet Request contenant les données de la requête.
     *
     * @return JsonResponse Une réponse JSON indiquant le statut de la suppression.
     */
    #[Route('/tool/level/delete', name: 'delete_level', methods: ['POST'])]
    public function delete_level(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $id_level = $data['id_level'];
            $this->delete("
DELETE FROM active_level WHERE id_level = $id_level;           
");
            $this->delete("
DELETE FROM levels_tools WHERE id_level = $id_level;           
");
            $this->delete("
DELETE FROM Logs WHERE id_level = $id_level;           
");
            $this->delete("
DELETE FROM Levels WHERE id_level = $id_level;           
");
            return new JsonResponse([
                'status' => 'success',
                'message' => 'Suppression réussi !'
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => "Echec de la suppression : " . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Récupère les données pour un nuage de points (scatter plot) en filtrant par langue et en limitant le nombre de résultats.
     *
     * @param Request $request L'objet Request contenant les données de la requête.
     *
     * @return JsonResponse Une réponse JSON contenant les données du nuage de points et les modèles associés.
     */
    #[Route('tool/language/statistics/scatterplot/{limit}/{id_language}', name: 'statistics_get_data_scatter_plot_filter_language', methods: ['GET'])]
    public function statistics_get_data_scatter_plot_filter_language(Request $request): JsonResponse
    {
        try {
            $limit = $request->attributes->get('limit');
            $id_language = $request->attributes->get('id_language');
            $data_scatterplot = $this->select("
SELECT DATE_FORMAT(date_log, '%H:%i') AS x_column,
       latency_log AS y_column,
       isError,
       id_log,
       name_level AS model
FROM Logs
JOIN Levels L on Logs.id_level = L.id_level
WHERE id_language = $id_language
ORDER BY date_log DESC
LIMIT $limit;
");
            $model_scatterplot = $this->select("
SELECT name_level AS model
FROM Levels
JOIN Logs L on Levels.id_level = L.id_level
WHERE L.id_language = $id_language
GROUP BY L.id_level;
");
            return new JsonResponse([
                'status' => '200',
                'data' => $data_scatterplot,
                'model' => $model_scatterplot
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => '500',
                'message' => 'Échec de la récupération : ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Télécharger les données des logs.
     *
     * @param Request $request L'objet Request contenant les données de la requête.
     *
     * @return Response La réponse HTTP avec le fichier de données en téléchargement.
     * @throws Exception
     */
    #[Route('/download/log', name: 'download_logs', methods: ['POST'])]
    public function download_logs(Request $request): Response
    {
        return $this->download_data($request, 'Logs', 'log');
    }


    /**
     * Récupérer le niveau du log.
     *
     * @param Request $request L'objet Request contenant les données de la requête.
     *
     * @return JsonResponse Une réponse JSON indiquant le statut de la suppression.
     */
    #[Route('/tool/log/level', name: 'get_level_log', methods: ['POST'])]
    public function get_level_log(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $id = $data['id'];
            $level = $this->select("
SELECT level
FROM Logs
JOIN Levels L on L.id_level = Logs.id_level
WHERE id_log = $id;
");
            return new JsonResponse([
                'status' => 'success',
                'level' => $level[0]['level']
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => "Echec de la récupération : " . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Lance l'exécution de la commande de récupération des logs d'une langue.
     *
     * @param Request $request L'objet Request contenant les données de la requête.
     *
     * @return JsonResponse Une réponse JSON indiquant le statut du lancement.
     */
    #[Route('/tool/language/get/log', name: 'run_get_log_language', methods: ['POST'])]
    public function run_get_log_language(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $projectDir = $this->getParameter('kernel.project_dir');
            $command = "php " . $projectDir . "/bin/console app:get-log-language " . escapeshellarg($data['id']);
            exec($command . ' 2>&1', $output, $return_var);
            if ($return_var !== 0) {
                throw new \Exception("Erreur lors de l'exécution de la commande : " . implode("\n", $output));
            }
            return new JsonResponse([
                'status' => '200'
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return new JsonResponse([
                'status' => '500',
                'message' => "Echec du lancement : " . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}