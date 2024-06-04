<?php

namespace App\Controller;

use Exception;
use Poliander\Cron\CronExpression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ParametersController extends DefaultController
{
    /**
     * Affiche les paramètres du site.
     *
     * @return Response La réponse HTTP contenant les paramètres.
     */
    #[Route('/parameters', name: 'parameters', methods: ['GET'])]
    public function parameters(): Response
    {
        $params = $this->select("
SELECT *
FROM Parameters;
");
        $last_log = $this->select("
SELECT *
FROM Logs
WHERE isError = FALSE
ORDER BY id_log DESC LIMIT 1;
");
        $CRON = $this->select("
SELECT *
FROM CRON;
");
        $levels = $this->select("
SELECT *
FROM Levels
ORDER BY level;
");
        return $this->render('Parameters/parameters.html.twig', [
            'title' => 'Paramètres',
            'params' => $params,
            'last_log' => $last_log,
            'CRON_info' => $CRON,
            'levels' => $levels
        ]);
    }


    /**
     * Met à jour la valeur d'un paramètre.
     *
     * @param Request $request La requête HTTP contenant les données pour mettre à jour le paramètre.
     *
     * @return JsonResponse La réponse JSON indiquant le statut de la mise à jour du paramètre.
     */
    #[Route('/parameters/update/one', name: 'parameters_update_one', methods: ['POST'])]
    public function parameters_update_one(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $id_params = $data['id_param'];
            $value = $data['value'];
            $this->update("
UPDATE Parameters
SET content_params = '$value'
WHERE id_params = $id_params;
");
            return new JsonResponse([
                'status' => 'success',
                'message' => 'Mise à jour réussie !'
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Echec de la mise à jour : ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Met à jour l'expression CRON.
     *
     * @param Request $request La requête HTTP contenant les données pour mettre à jour l'expression CRON.
     *
     * @return JsonResponse La réponse JSON indiquant le statut de la mise à jour de l'expression CRON.
     */
    #[Route('/parameters/update/cron', name: 'parameters_update_cron', methods: ['POST'])]
    public function parameters_update_cron(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $id_CRON = $data['id'];
            $value = $data['value'];
            $this->update("
UPDATE CRON
SET expression_CRON = '$value'
WHERE id_CRON = $id_CRON;
");
            $answer = $this->updateCronFile();
            if ($answer['status'] !== "200") {
                throw new Exception($answer['message']);
            }
            return new JsonResponse([
                'status' => 'success',
                'message' => 'Mise à jour réussie !',
                'answer' => $answer
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Echec de la mise à jour : ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Met à jour le fichier CRON.
     *
     * @return array Un tableau contenant le statut de la mise à jour du fichier CRON et un message associé.
     */
    private function updateCronFile(): array
    {
        $pathToCronFile = $_ENV['CRON_FILE'] . '/crontab';
        $destinationPath = $_ENV['CRON_FILE_COPY'] . '/crontabCopie.txt';
        exec('cp ' . $pathToCronFile . ' ' . $destinationPath);
        $fileContent = file_get_contents($destinationPath);
        if ($fileContent === false) {
            return array(
                "status" => "500",
                "message" => "Erreur lors de la copie du fichier."
            );
        } else {
            try {
                $newFile = "# /etc/crontab: system-wide crontab
# Unlike any other crontab you don't have to run the `crontab'
# command to install the new version when you edit this files
# and files in /etc/cron.d. These files also have username fields,
# that none of the other crontabs do.

SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

# Example of job definition:
# .---------------- minute (0 - 59)
# |  .------------- hour (0 - 23)
# |  |  .---------- day of month (1 - 31)
# |  |  |  .------- month (1 - 12) OR jan,feb,mar,apr ...
# |  |  |  |  .---- day of week (0 - 6) (Sunday=0 or 7) OR sun,mon,tue,wed,thu,fri,sat
# |  |  |  |  |
# *  *  *  *  * user-name command to be executed
17 *	* * *	root	cd / && run-parts --report /etc/cron.hourly
25 6	* * *	root	test -x /usr/sbin/anacron || { cd / && run-parts --report /etc/cron.daily; }
47 6	* * 7	root	test -x /usr/sbin/anacron || { cd / && run-parts --report /etc/cron.weekly; }
52 6	1 * *	root	test -x /usr/sbin/anacron || { cd / && run-parts --report /etc/cron.monthly; }
#

";
                $listCron = $this->select("SELECT * FROM CRON;");
                foreach ($listCron as &$cron) {
                    if ($cron[2] == "CRON_COMMANDE_GET_LOG") {
                        $newFile = $newFile . $cron[1] . ' ' . $_ENV['CRON_COMMANDE_GET_LOG'] . "\n";
                    } else if ($cron[2] == "CRON_COMMANDE_GET_THREAD") {
                        $newFile = $newFile . $cron[1] . ' ' . $_ENV['CRON_COMMANDE_GET_THREAD'] . "\n";
                    } else if ($cron[2] == "CRON_COMMANDE_GET_BACKUP_ASSISTANTS") {
                        $newFile = $newFile . $cron[1] . ' ' . $_ENV['CRON_COMMANDE_GET_BACKUP_ASSISTANTS'] . "\n";
                    }
                }
                file_put_contents($_ENV['CRON_FILE'] . '/crontab', $newFile);
                $status = exec('systemctl status cron');
                return array(
                    "status" => "200",
                    "message" => "$newFile"
                );
            } catch (Exception $e) {
                return array(
                    "status" => "500",
                    "message" => "Erreur lors de l'écriture du fichier : $e"
                );
            }

        }
    }


    /**
     * Ajoute une nouvelle entrée CRON dans la base de données.
     *
     * @param Request $request La requête contenant les données de la nouvelle entrée CRON.
     * @return JsonResponse La réponse JSON indiquant le statut de l'ajout et un message associé.
     */
    #[Route('/parameters/add/cron', name: 'parameters_add_cron', methods: ['POST'])]
    public function parameters_add_cron(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $value = $data['value'];
            $commande = $data['commande'];
            $this->insert("
INSERT INTO CRON(expression_CRON, commande_CRON)
VALUES ('$value', '$commande');
");
            $id = $this->select("
SELECT MAX(id_cron) AS id_cron
FROM CRON;
");
            return new JsonResponse([
                'status' => 'success',
                'message' => 'Ajout réussie !',
                'id' => $id[0]['id_cron']
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => "Echec de l'ajout : " . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Supprime une entrée CRON de la base de données.
     *
     * @param Request $request La requête contenant l'ID de l'entrée CRON à supprimer.
     * @return JsonResponse La réponse JSON indiquant le statut de la suppression et un message associé.
     */
    #[Route('/parameters/delete/cron', name: 'parameters_delete_cron', methods: ['POST'])]
    public function parameters_delete_cron(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $id = $data['id'];
            $this->insert("
DELETE FROM CRON
       WHERE id_CRON = $id;
");
            return new JsonResponse([
                'status' => 'success',
                'message' => 'Suppression réussie !'
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => "Echec de la suppression : " . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Arrête le service CRON et met à jour le fichier CRON.
     *
     * @param Request $request La requête pour arrêter le service CRON.
     * @return JsonResponse La réponse JSON indiquant le statut de l'arrêt et un message associé.
     */
    #[Route('/parameters/stop/cron', name: 'parameters_stop_cron', methods: ['POST'])]
    public function parameters_stop_cron(Request $request): JsonResponse
    {
        try {
            $answer = $this->updateCronFile();
            if ($answer['status'] !== "200") {
                throw new Exception($answer['message']);
            }
            exec('systemctl stop cron');
            return new JsonResponse([
                'status' => 'success',
                'message' => 'Arrêt réussie !'
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => "Echec de l'arrêt : " . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Supprime un niveau de log.
     *
     * @param Request $request La requête contenant les données de suppression.
     * @return JsonResponse La réponse JSON indiquant le statut de la suppression et un message associé.
     */
    #[Route('/parameters/level/delete', name: 'parameters_level_delete', methods: ['POST'])]
    public function parameters_level_delete(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $id = $data['id'];
            $this->insert("
DELETE
FROM Logs
WHERE id_level = $id;
");
            $this->update("
UPDATE Languages
SET active_level_id = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', active_level_id, ','), CONCAT(',', '$id', ','), ','))
WHERE FIND_IN_SET('$id', active_level_id);
");
            $this->insert("
DELETE
FROM Levels
WHERE id_level = $id;
");
            return new JsonResponse([
                'status' => 'success',
                'message' => 'Suppression réussie !'
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => "Echec de la suppression : " . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Met à jour les informations d'un niveau de log.
     *
     * @param Request $request La requête contenant les données de mise à jour.
     * @return JsonResponse La réponse JSON indiquant le statut de la mise à jour et un message associé.
     */
    #[Route('/parameters/level/update', name: 'parameters_level_update', methods: ['POST'])]
    public function parameters_level_update(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $id = $data['id'];
            $name = $data['name'];
            $value = $data['value'];
            $this->update("
UPDATE Levels
SET name_level = '$name', level = $value
WHERE id_level = $id;
");
            return new JsonResponse([
                'status' => 'success',
                'message' => 'Mise à jour réussie !'
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Echec de la mise à jour : ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Valide une expression CRON.
     *
     * @param Request $request La requête contenant l'expression CRON à valider.
     * @return JsonResponse La réponse JSON indiquant le statut de validation et un message associé.
     */
    #[Route('/parameters/cron/validation', name: 'parameters_cron_validation', methods: ['POST'])]
    public function parameters_cron_validation(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $expression = new CronExpression($data['value']);
            if ($data['value'] === "@yearly" || $data['value'] === "@annually" ||
                $data['value'] === "@monthly" || $data['value'] === "@weekly" ||
                $data['value'] === "@daily" || $data['value'] === "@hourly" ||
                $data['value'] === "@reboot") {
                return new JsonResponse([
                    'status' => 'success',
                    'message' => 'Expression CRON valide : ' . $data['value']
                ], Response::HTTP_OK);
            }
            $isValid = $expression->isValid();
            if ($isValid) {
                return new JsonResponse([
                    'status' => 'success',
                    'message' => 'Expression CRON valide : ' . $data['value']
                ], Response::HTTP_OK);
            }
            return new JsonResponse([
                'status' => 'error',
                'message' => "Echec de la mise à jour : L'expression CRON n'est pas valide ! " . $data['value']
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Echec de la mise à jour : ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}