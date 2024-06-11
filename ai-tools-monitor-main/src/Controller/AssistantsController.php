<?php

namespace App\Controller;

use Exception;
use PDOException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AssistantsController extends DefaultController
{


    /**
     * Afficher la liste des assistants.
     *
     * @return Response La réponse HTTP contenant la liste des assistants.
     */
    #[Route('/assistants', name: 'list_assistants', methods: ['GET'])]
    public function list_assistants(): Response
    {
        $list_assistants = $this->select("
SELECT A.*,
       COALESCE(E.nbErrors, 0) AS nbErrors
FROM Assistants A
         LEFT JOIN (
    SELECT T.id_assistant,
           COUNT(*) AS nbErrors
    FROM Threads T
    WHERE T.isError = 1
      AND T.date_thread >= NOW() - INTERVAL 1 DAY
    GROUP BY T.id_assistant
) E ON A.id_assistant = E.id_assistant;
");
        return $this->render('Assistants/list_assistants.html.twig', [
            'title' => 'Liste des assistants',
            'assistants' => $list_assistants
        ]);
    }


    /**
     * Afficher les informations sur un assistant spécifique.
     *
     * @param Request $request La requête HTTP contenant l'identifiant de l'assistant.
     *
     * @return Response La réponse HTTP contenant les informations sur l'assistant.
     */
    #[Route('/assistants/{id}', name: 'one_assistant', methods: ['GET'])]
    public function one_assistant(Request $request): Response
    {
        $id_assistant = $request->attributes->get('id');
        $informations = $this->select("
SELECT *
FROM Assistants
WHERE id_assistant = $id_assistant;
");
        $threads = $this->select("
SELECT id_thread, date_thread, latency_thread, isError
FROM Threads
         LEFT JOIN Errors_Types ET on ET.id_error_type = Threads.id_error_type
WHERE id_assistant = $id_assistant
ORDER BY date_thread DESC;
");
        $informations[0]['object_assistant'] = json_decode($informations[0]['object_assistant'], true);
        return $this->render('Assistants/one_assistant.html.twig', [
            'title' => 'Informations sur un assistant',
            'informations' => $informations[0],
            'threads' => $threads
        ]);
    }


    /**
     * Supprimer un thread.
     *
     * @param Request $request La requête HTTP contenant l'identifiant du thread à supprimer.
     *
     * @return JsonResponse La réponse JSON indiquant le statut de la suppression.
     */
    #[Route('/assistants/thread/delete', name: 'delete_thread', methods: ['POST'])]
    public function delete_thread(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $id_thread = $data['id'];
            $this->delete("
DELETE
FROM Threads
WHERE id_thread = $id_thread;
");
            return new JsonResponse([
                'status' => '200',
                'message' => 'Suppression réussie !'
            ], Response::HTTP_OK);
        } catch (PDOException $e) {
            return new JsonResponse([
                'status' => '500',
                'message' => 'Echec de la suppression : ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Afficher les informations sur un thread spécifique.
     *
     * @param Request $request La requête HTTP contenant l'identifiant du thread.
     *
     * @return Response La réponse HTTP contenant les informations sur le thread.
     */
    #[Route('/assistants/thread/{id}', name: 'one_thread', methods: ['GET'])]
    public function one_thread(Request $request): Response
    {
        $id_thread = $request->attributes->get('id');
        $informations = $this->getThread($id_thread);
        return $this->render('Assistants/one_thread.html.twig', [
            'title' => 'Informations sur un thread',
            'informations' => $informations
        ]);
    }


    /**
     * Récupère les informations sur un thread spécifique.
     *
     * @param mixed $id_thread L'identifiant du thread à récupérer.
     *
     * @return array|bool Les informations sur le thread ou false si aucun thread n'est trouvé.
     */
    private function getThread(mixed $id_thread): array|bool
    {
        $informations = $this->select("
SELECT *
FROM Threads
         LEFT JOIN Errors_Types ET on ET.id_error_type = Threads.id_error_type
WHERE id_thread = $id_thread;
");
        $informations[0]['object_list_messages'] = json_decode($informations[0]['object_list_messages'], true);
        $informations[0]['object_run'] = json_decode($informations[0]['object_run'], true);
        $informations[0]['object_thread'] = json_decode($informations[0]['object_thread'], true);
        $informations[0]['instructions_assistant'] = json_decode($informations[0]['instructions_assistant'], true);
        return $informations[0];
    }


    /**
     * Comparer deux threads spécifiques et afficher leurs informations.
     *
     * @param Request $request La requête HTTP contenant les identifiants des threads à comparer.
     *
     * @return Response La réponse HTTP contenant les informations sur les deux threads comparés.
     */
    #[Route('/assistants/thread/compare/{thread1}/{thread2}', name: 'compare_thread', methods: ['GET'])]
    public function compare_thread(Request $request): Response
    {
        $id_thread_1 = $request->attributes->get('thread1');
        $id_thread_2 = $request->attributes->get('thread2');
        $thread1 = $this->getThread($id_thread_1);
        $thread2 = $this->getThread($id_thread_2);
        return $this->render('Assistants/compare_thread.html.twig', [
            'title' => 'Informations sur deux threads',
            'thread1' => $thread1,
            'thread2' => $thread2
        ]);
    }


    /**
     * Télécharger les données des threads.
     *
     * @param Request $request La requête HTTP contenant les données à télécharger.
     *
     * @return Response La réponse HTTP contenant les données des threads à télécharger.
     * @throws Exception
     */
    #[Route('/download/thread', name: 'download_threads', methods: ['POST'])]
    public function download_threads(Request $request): Response
    {
        return $this->download_data($request, 'Threads', 'thread');
    }


    /**
     * Ajouter un nouvel assistant à partir des données fournies.
     *
     * @param Request $request La requête HTTP contenant les données de l'assistant à ajouter.
     *
     * @return JsonResponse La réponse JSON indiquant le statut de l'ajout de l'assistant.
     */
    #[Route('/assistants/add/one', name: 'form_add_assistant', methods: ['POST'])]
    public function form_add_assistant(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $name = $data['name'];
        $api_name = $data['api_name'];
        $url = "https://api.openai.com/v1/assistants/" . $api_name;
        try {
            $assistant = $this->curlRequest($url, [
                [CURLOPT_RETURNTRANSFER, true],
                [CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $_ENV['API_KEY'],
                    'OpenAI-Beta: assistants=v2']
                ],
                [CURLOPT_SSL_VERIFYPEER, false]
            ]);
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => '500',
                'message' => 'Echec de la récupération : ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        $this->insertBindParams("
INSERT INTO Assistants(name_assistant, object_assistant, isActive)
VALUES (:name, :assistant, FALSE);
", [':name' => $name, ':assistant' => $assistant]);
        $assistant = $this->select("
SELECT *
FROM Assistants
ORDER BY id_assistant DESC
LIMIT 1;
");
        return new JsonResponse([
            'status' => '200',
            'name' => $name,
            'api_name' => $api_name,
            'assistant' => $assistant,
            'id' => $assistant[0]['id_assistant']
        ], Response::HTTP_OK);
    }


    /**
     * Modifier le statut d'un assistant.
     *
     * @param Request $request La requête HTTP contenant les données pour modifier le statut de l'assistant.
     *
     * @return JsonResponse La réponse JSON indiquant le statut de la modification du statut de l'assistant.
     */
    #[Route('/assistants/update/status', name: 'change_status_assistant', methods: ['POST'])]
    public function change_status_assistant(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $id_assistant = $data['id_assistant'];
        $status = $data['status'];
        if ($status === false) {
            $status = "FALSE";
        } else {
            $status = "TRUE";
        }
        try {
            $this->update("
UPDATE Assistants
SET isActive = $status
WHERE id_assistant = $id_assistant;
");
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => '500',
                'message' => 'Echec de la mise à jour du status : ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        return new JsonResponse([
            'status' => '200',
            'message' => "Status de l'assistant mis à jour !"
        ], Response::HTTP_OK);
    }


    /**
     * Mettre à jour les informations d'un assistant.
     *
     * @param Request $request La requête HTTP contenant les nouvelles données de l'assistant à mettre à jour.
     *
     * @return JsonResponse La réponse JSON indiquant le statut de la mise à jour de l'assistant.
     */
    #[Route('/assistants/update/one', name: 'update_assistant', methods: ['POST'])]
    public function update_assistant(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $id_assistant = $data['id_assistant'];
        $name = $data['name'];
        $api_name = $data['api_name'];
        $url = "https://api.openai.com/v1/assistants/" . $api_name;
        try {
            try {
                $assistant = $this->curlRequest($url, [
                    [CURLOPT_RETURNTRANSFER, true],
                    [CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $_ENV['API_KEY'],
                        'OpenAI-Beta: assistants=v2']
                    ],
                    [CURLOPT_SSL_VERIFYPEER, false]
                ]);
            } catch (Exception $e) {
                return new JsonResponse([
                    'status' => '500',
                    'message' => 'Echec de la récupération : ' . $e->getMessage()
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
            $this->updateBindParams("
UPDATE Assistants
SET name_assistant = :name, object_assistant = :object_assistant
WHERE id_assistant = :id;
", [':name' => $name, ':object_assistant' => $assistant, ':id' => $id_assistant]);
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => '500',
                'message' => "Echec de la mise à jour de l'assistant : " . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        return new JsonResponse([
            'status' => '200',
            'message' => "Assistant mis à jour !",
            'new_object_assistant' => $assistant
        ], Response::HTTP_OK);
    }


    /**
     * Supprimer un assistant ainsi que tous les threads associés.
     *
     * @param Request $request La requête HTTP contenant l'identifiant de l'assistant à supprimer.
     *
     * @return JsonResponse La réponse JSON indiquant le statut de la suppression de l'assistant.
     */
    #[Route('/assistants/delete/one', name: 'delete_assistant', methods: ['POST'])]
    public function delete_assistant(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $id_assistant = $data['id_assistant'];
        try {
            $this->delete("
DELETE
FROM Threads
WHERE id_assistant = $id_assistant;
");
            $this->delete("
DELETE
FROM Assistants
WHERE id_assistant = $id_assistant;
");
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => '500',
                'message' => "Echec de la suppression de l'assistant : " . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        return new JsonResponse([
            'status' => '200',
            'message' => "Assistant supprimé !"
        ], Response::HTTP_OK);
    }


    /**
     * Récupérer la liste des assistants depuis l'API OpenAI.
     *
     * @param Request $request La requête HTTP.
     *
     * @return JsonResponse La réponse JSON contenant la liste des assistants ou un message d'erreur en cas d'échec.
     */
    #[Route('/assistants/list/get', name: 'get_list_assistant', methods: ['GET'])]
    public function get_list_assistant(Request $request): JsonResponse
    {
        $list_assistants = [];
        try {
            $assistants = $this->curlRequest("https://api.openai.com/v1/assistants", [
                [CURLOPT_RETURNTRANSFER, true],
                [CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $_ENV['API_KEY'],
                    'OpenAI-Beta: assistants=v2']
                ],
                [CURLOPT_SSL_VERIFYPEER, false]
            ]);
            $assistants = json_decode($assistants);
            if (is_object($assistants) && property_exists($assistants, 'data')) {
                foreach ($assistants->data as $assistant) {
                    $list_assistants[] = $assistant;
                }
            } else {
                throw new Exception("La réponse de l'API d'OpenIA ne contient pas les éléments attendus.");
            }
            $last_id = $assistants->last_id;
            $has_more = $assistants->has_more;
            while ($has_more) {
                $assistants = $this->curlRequest("https://api.openai.com/v1/assistants?orderdesc&after=" . $last_id, [
                    [CURLOPT_RETURNTRANSFER, true],
                    [CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $_ENV['API_KEY'],
                        'OpenAI-Beta: assistants=v2']
                    ],
                    [CURLOPT_SSL_VERIFYPEER, false]
                ]);
                $assistants = json_decode($assistants);
                if (is_object($assistants) && property_exists($assistants, 'data')) {
                    foreach ($assistants->data as $assistant) {
                        $list_assistants[] = $assistant;
                    }
                } else {
                    throw new Exception("La réponse de l'API d'OpenIA ne contient pas les éléments attendus.");
                }
                $last_id = $assistants->last_id;
                $has_more = $assistants->has_more;
            }
            return new JsonResponse([
                'status' => '200',
                'data' => $list_assistants
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse([
                'status' => '500',
                'message' => 'Echec de la récupération des assistants : ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}