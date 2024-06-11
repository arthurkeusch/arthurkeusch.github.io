<?php

namespace App\Controller;

use DateTime;
use DateTimeZone;
use Exception;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;

class BackupController extends DefaultController
{


    /**
     * Afficher la liste des sauvegardes.
     *
     * @param Request $request La requête HTTP.
     *
     * @return Response La réponse HTTP contenant la liste des sauvegardes.
     */
    #[Route('/backup', name: 'list_backup', methods: ['GET'])]
    public function list_backup(Request $request): Response
    {
        $list_backups = $this->select("
SELECT *
FROM BackUp
ORDER BY date_backup DESC;
");
        return $this->render('Backup/list_backup.html.twig', [
            'title' => 'Liste des sauvegardes',
            'list_backups' => $list_backups
        ]);
    }


    /**
     * Afficher les informations sur une sauvegarde spécifique.
     *
     * @param Request $request La requête HTTP contenant l'identifiant de la sauvegarde.
     *
     * @return Response La réponse HTTP contenant les informations sur la sauvegarde.
     */
    #[Route('/backup/{id}', name: 'one_backup', methods: ['GET'])]
    public function one_backup(Request $request): Response
    {
        $id_backup = $request->attributes->get('id');
        $informations = $this->select("
SELECT *
FROM BackUp
WHERE id_backup = $id_backup;
");

        $list_backups = $this->select("
SELECT id_backup_assistant, object_backup_assistant
FROM BackUp_Assistants
WHERE id_backup = $id_backup;
");

        foreach ($list_backups as &$backup) {
            $object_backup_assistant = json_decode($backup['object_backup_assistant'], true);
            $backup['object_backup_assistant'] = $object_backup_assistant['name'];
        }

        return $this->render('Backup/one_backup.html.twig', [
            'title' => "Informations d'une sauvegarde",
            'informations' => $informations[0],
            'list_backups' => $list_backups
        ]);
    }


    /**
     * Télécharger les sauvegardes spécifiées au format CSV ou JSON.
     *
     * @param Request $request La requête HTTP contenant les données des sauvegardes à télécharger.
     *
     * @return Response La réponse HTTP contenant les sauvegardes téléchargées.
     *
     * @throws Exception Si une erreur survient lors de la suppression des anciens fichiers ou de la création du fichier de sauvegarde.
     */
    #[Route('/download/backup', name: 'download_backups', methods: ['GET'])]
    public function download_backups(Request $request): Response
    {
        $this->deleteOldFiles("files", 10);
        $data = json_decode($request->getContent(), true);
        $typeFile = $data['type'];
        $listId = $data['listId'];
        $timezone = new DateTimeZone('Europe/Paris');
        $dateTime = new DateTime('now', $timezone);
        $content = [];

        foreach ($listId as $id) {
            $data = $this->select("
SELECT object_backup_assistant
FROM BackUp_Assistants
WHERE id_backup = $id;
");
            if ($typeFile === "csv") {
                $content[] = $this->arrayToCSV($data);
            } else {
                $content[] = $this->arrayToJSON($data);
            }
        }

        if ($typeFile === "csv") {
            $encoded_content = $this->createCSV($content);
        } else {
            $encoded_content = $this->createJSON($content);
        }

        $filename = "backup_" . $dateTime->format("Y_m_d-H_i_s") . "." . $typeFile;
        $filepath = "files/" . $filename;
        $this->writeFile($filepath, $encoded_content);

        $response = new BinaryFileResponse($filepath);
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        );
        return $response;
    }


    /**
     * Afficher les informations sur une sauvegarde d'assistant spécifique.
     *
     * @param Request $request La requête HTTP contenant l'identifiant de la sauvegarde d'assistant.
     *
     * @return Response La réponse HTTP contenant les informations sur la sauvegarde d'assistant.
     */
    #[Route('/backup/assistant/{id}', name: 'one_backup_assistant', methods: ['GET'])]
    public function one_backup_assistant(Request $request): Response
    {
        $id_backup_assistant = $request->attributes->get('id');
        $informations = $this->select("
        SELECT date_backup_assistant, object_backup_assistant
        FROM BackUp_Assistants
        WHERE id_backup_assistant = $id_backup_assistant;
    ");

        $object_backup_assistant = json_decode($informations[0]['object_backup_assistant'], true);
        $pretty_json = json_encode($object_backup_assistant, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return $this->render('Backup/one_backup_assistant.html.twig', [
            'title' => "Informations d'une sauvegarde d'assistant",
            'informations' => $informations[0],
            'pretty_json' => $pretty_json,
        ]);
    }


    /**
     * Supprimer une sauvegarde ainsi que toutes les sauvegardes d'assistants associées.
     *
     * @param Request $request La requête HTTP contenant l'identifiant de la sauvegarde à supprimer.
     *
     * @return JsonResponse La réponse JSON indiquant le statut de la suppression de la sauvegarde.
     */
    #[Route('/backup/delete/one', name: 'delete_backup', methods: ['POST'])]
    public function delete_backup(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $id_backup = $data['id_backup'];
        try {
            $this->delete("
DELETE
FROM BackUp_Assistants
WHERE id_backup = $id_backup;
");
            $this->delete("
DELETE
FROM BackUp
WHERE id_backup = $id_backup;
");
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => '500',
                'message' => "Echec de la suppression de la sauvegarde : " . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        return new JsonResponse([
            'status' => '200',
            'message' => "Sauvegarde supprimée !"
        ], Response::HTTP_OK);
    }
}