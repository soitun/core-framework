<?php

namespace Webkul\UVDesk\CoreFrameworkBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Webkul\UVDesk\CoreFrameworkBundle\Tickets\QuickActionButtonCollection;
use Webkul\UVDesk\CoreFrameworkBundle\Repository\TicketRepository;
use Webkul\UVDesk\CoreFrameworkBundle\Services\UserService;
use Webkul\UVDesk\CoreFrameworkBundle\Services\TicketService;
use Webkul\UVDesk\CoreFrameworkBundle\Entity as CoreEntities;
use Webkul\UVDesk\CoreFrameworkBundle\Form as CoreFrameworkBundleForms;
use Webkul\UVDesk\CoreFrameworkBundle\Workflow\Events as CoreWorkflowEvents;
use Webkul\UVDesk\CoreFrameworkBundle\DataProxies as CoreFrameworkBundleDataProxies;

class Ticket extends AbstractController
{
    private $userService;
    private $translator;
    private $eventDispatcher;
    private $ticketService;
    private $kernel;
    private $entityManagerInterface;

    public function __construct(UserService $userService, TranslatorInterface $translator, TicketService $ticketService, EventDispatcherInterface $eventDispatcher, KernelInterface $kernel, EntityManagerInterface $entityManagerInterface)
    {
        $this->userService = $userService;
        $this->translator = $translator;
        $this->ticketService = $ticketService;
        $this->eventDispatcher = $eventDispatcher;
        $this->entityManagerInterface = $entityManagerInterface;
        $this->kernel = $kernel;
    }

    public function listTicketCollection(Request $request)
    {
        $entityManager = $this->entityManagerInterface;

        return $this->render('@UVDeskCoreFramework//ticketList.html.twig', [
            'ticketStatusCollection'   => $entityManager->getRepository(CoreEntities\TicketStatus::class)->findAll(),
            'ticketTypeCollection'     => $entityManager->getRepository(CoreEntities\TicketType::class)->findByIsActive(true),
            'ticketPriorityCollection' => $entityManager->getRepository(CoreEntities\TicketPriority::class)->findAll(),
        ]);
    }

    public function loadTicket($ticketId, QuickActionButtonCollection $quickActionButtonCollection, ContainerInterface $container)
    {
        $entityManager = $this->entityManagerInterface;
        $userRepository = $entityManager->getRepository(CoreEntities\User::class);
        $ticketRepository = $entityManager->getRepository(CoreEntities\Ticket::class);

        $ticket = $ticketRepository->findOneById($ticketId);

        if (empty($ticket)) {
            throw new NotFoundHttpException('Page not found!');
        }

        $user = $this->userService->getSessionUser();

        // Proceed only if user has access to the resource
        if (false == $this->ticketService->isTicketAccessGranted($ticket, $user)) {
            throw new \Exception('Access Denied', 403);
        }

        $agent = $ticket->getAgent();
        $customer = $ticket->getCustomer();

        if (! empty($agent)) {
            $ticketAssignAgent = $agent->getId();
            $currentUser = $user->getId();
        }

        // Mark as viewed by agents
        if (false == $ticket->getIsAgentViewed()) {
            $ticket
                ->setIsAgentViewed(true)
                ->setSkipUpdatedAt(true);

            $entityManager->persist($ticket);
            $entityManager->flush();
        }

        // Ticket Authorization
        $supportRole = $user->getCurrentInstance()->getSupportRole()->getCode();
        switch ($supportRole) {
            case 'ROLE_ADMIN':
            case 'ROLE_SUPER_ADMIN':
                break;
            case 'ROLE_AGENT':
                $accessLevel = (int) $user->getCurrentInstance()->getTicketAccessLevel();
                switch ($accessLevel) {
                    case TicketRepository::TICKET_GLOBAL_ACCESS:
                        break;
                    case TicketRepository::TICKET_GROUP_ACCESS:
                        $supportGroups = array_map(function ($supportGroup) {
                            return $supportGroup->getId();
                        }, $user->getCurrentInstance()->getSupportGroups()->getValues());
                        $ticketAccessableGroups = $ticket->getSupportGroup() ? [$ticket->getSupportGroup()->getId()] : [];

                        if ($ticket->getSupportTeam()) {
                            $ticketSupportTeamGroups = array_map(function ($supportGroup) {
                                return $supportGroup->getId();
                            }, $ticket->getSupportTeam()->getSupportGroups()->getValues());
                            $ticketAccessableGroups = array_merge($ticketAccessableGroups, $ticketSupportTeamGroups);
                        }
                        $isAccessableGroupFound = false;
                        foreach ($ticketAccessableGroups as $groupId) {
                            if (in_array($groupId, $supportGroups)) {
                                $isAccessableGroupFound = true;
                                break;
                            }
                        }
                        if (! $isAccessableGroupFound && !($ticketAssignAgent == $currentUser)) {
                            throw new NotFoundHttpException('Page not found!');
                        }
                        break;
                    case TicketRepository::TICKET_TEAM_ACCESS:
                        $supportTeams = array_map(function ($supportTeam) {
                            return $supportTeam->getId();
                        }, $user->getCurrentInstance()->getSupportTeams()->getValues());
                        $supportTeam = $ticket->getSupportTeam();
                        if (!($supportTeam && in_array($supportTeam->getId(), $supportTeams)) && !($ticketAssignAgent == $currentUser)) {
                            throw new NotFoundHttpException('Page not found!');
                        }
                        break;
                    default:
                        $collaborators = array_map(function ($collaborator) {
                            return $collaborator->getId();
                        }, $ticket->getCollaborators()->getValues());
                        $accessableAgents = array_merge($collaborators, $ticket->getAgent() ? [$ticket->getAgent()->getId()] : []);
                        if (!in_array($user->getId(), $accessableAgents)) {
                            throw new NotFoundHttpException('Page not found!');
                        }
                        break;
                }
                break;
            default:
                throw new NotFoundHttpException('Page not found!');
        }

        $ratings = $ticket->getRatings()->toArray();
        $quickActionButtonCollection->prepareAssets();

        $userService = $container->get('user.service');
        $customerDetails = $customer->getCustomerInstance()->getPartialDetails();

        if (! empty($customerDetails)) {
            $customerDetails['lastLogin'] = !empty($customerDetails['lastLogin'])
            ? $userService->getLocalizedFormattedTime($customerDetails['lastLogin']) 
            : 'NA';
        }

        return $this->render('@UVDeskCoreFramework//ticket.html.twig', [
            'ticket'                    => $ticket,
            'totalReplies'              => $ticketRepository->countTicketTotalThreads($ticket->getId()),
            'totalCustomerTickets'      => ($ticketRepository->countCustomerTotalTickets($customer, $container) - 1),
            'initialThread'             => $this->ticketService->getTicketInitialThreadDetails($ticket),
            'ticketAgent'               => ! empty($agent) ? $agent->getAgentInstance()->getPartialDetails() : null,
            'customer'                  => $customerDetails,
            'currentUserDetails'        => $user->getAgentInstance()->getPartialDetails(),
            'supportGroupCollection'    => $userRepository->getSupportGroups(),
            'supportTeamCollection'     => $userRepository->getSupportTeams(),
            'ticketStatusCollection'    => $entityManager->getRepository(CoreEntities\TicketStatus::class)->findAll(),
            'ticketTypeCollection'      => $entityManager->getRepository(CoreEntities\TicketType::class)->findByIsActive(true),
            'ticketPriorityCollection'  => $entityManager->getRepository(CoreEntities\TicketPriority::class)->findAll(),
            'ticketNavigationIteration' => $ticketRepository->getTicketNavigationIteration($ticket, $container),
            'ticketLabelCollection'     => $ticketRepository->getTicketLabelCollection($ticket, $user),
            'ticketRating'              => ! empty($ratings) && isset($ratings[0]) ? $ratings[0]->getStars() : [],
        ]);
    }

    public function saveTicket(Request $request)
    {
        $requestParams = $request->request->all();
        $entityManager = $this->entityManagerInterface;
        $response = $this->redirect($this->generateUrl('helpdesk_member_ticket_collection'));

        if (
            $request->getMethod() != 'POST'
            || false == $this->userService->isAccessAuthorized('ROLE_AGENT_CREATE_TICKET')
        ) {
            return $response;
        }

        $website = $entityManager->getRepository(CoreEntities\Website::class)->findOneByCode('knowledgebase');
        if (
            ! empty($requestParams['from'])
            && $this->ticketService->isEmailBlocked($requestParams['from'], $website)
        ) {
            $request->getSession()->getFlashBag()->set('warning', $this->translator->trans('Warning ! Cannot create ticket, given email is blocked by admin.'));

            return $this->redirect($this->generateUrl('helpdesk_member_ticket_collection'));
        }

        // Get referral ticket if any
        $ticketValidationGroup = 'CreateTicket';
        $referralURL = $request->headers->get('referer');

        if (! empty($referralURL)) {
            $iterations = explode('/', $referralURL);
            $referralId = array_pop($iterations);
            $expectedReferralURL = $this->generateUrl('helpdesk_member_ticket', ['ticketId' => $referralId], UrlGeneratorInterface::ABSOLUTE_URL);

            if ($referralURL === $expectedReferralURL) {
                $referralTicket = $entityManager->getRepository(CoreEntities\Ticket::class)->findOneById($referralId);

                if (! empty($referralTicket)) {
                    $ticketValidationGroup = 'CustomerCreateTicket';
                }
            }
        }

        try {
            if ($this->userService->isFileExists('apps/uvdesk/custom-fields')) {
                $customFieldsService = $this->get('uvdesk_package_custom_fields.service');
            } else if ($this->userService->isFileExists('apps/uvdesk/form-component')) {
                $customFieldsService = $this->get('uvdesk_package_form_component.service');
            }

            if (! empty($customFieldsService)) {
                extract($customFieldsService->customFieldsValidation($request, 'user'));
            }
        } catch (\Exception $e) {
            // @TODO: Log execption message
        }

        if (! empty($errorFlashMessage)) {
            $this->addFlash('warning', $errorFlashMessage);
        }

        $ticketProxy = new CoreFrameworkBundleDataProxies\CreateTicketDataClass();
        $form = $this->createForm(CoreFrameworkBundleForms\CreateTicket::class, $ticketProxy);

        // Validate Ticket Details
        $form->submit($requestParams);

        if (false == $form->isSubmitted() || false == $form->isValid()) {
            if (false === $form->isValid()) {
                // @TODO: We need to handle form errors gracefully.
                // We should also look into switching to an xhr request instead.
                // $form->getErrors(true);
            }

            return $this->redirect(!empty($referralURL) ? $referralURL : $this->generateUrl('helpdesk_member_ticket_collection'));
        }

        if ('CustomerCreateTicket' === $ticketValidationGroup && !empty($referralTicket)) {
            // Retrieve customer details from referral ticket
            $customer = $referralTicket->getCustomer();
            $customerPartialDetails = $customer->getCustomerInstance()->getPartialDetails();
        } else if (null != $ticketProxy->getFrom() && null != $ticketProxy->getName()) {
            // Create customer if account does not exists
            $customer = $entityManager->getRepository(CoreEntities\User::class)->findOneByEmail($ticketProxy->getFrom());

            if (
                empty($customer)
                || null == $customer->getCustomerInstance()
            ) {
                $role = $entityManager->getRepository(CoreEntities\SupportRole::class)->findOneByCode('ROLE_CUSTOMER');

                // Create User Instance
                $customer = $this->userService->createUserInstance($ticketProxy->getFrom(), $ticketProxy->getName(), $role, [
                    'source' => 'website',
                    'active' => true
                ]);
            }
        }

        $ticketData = [
            'from'        => $customer->getEmail(),
            'name'        => $customer->getFirstName() . ' ' . $customer->getLastName(),
            'type'        => $ticketProxy->getType(),
            'subject'     => $ticketProxy->getSubject(),
            // @TODO: We need to enable support for html messages.
            // Our focus here instead should be to prevent XSS (filter js)
            'message'     => str_replace(['&lt;script&gt;', '&lt;/script&gt;'], '', htmlspecialchars($ticketProxy->getReply())),
            'firstName'   => $customer->getFirstName(),
            'lastName'    => $customer->getLastName(),
            'type'        => $ticketProxy->getType(),
            'role'        => 4,
            'source'      => 'website',
            'threadType'  => 'create',
            'createdBy'   => 'agent',
            'customer'    => $customer,
            'user'        => $this->getUser(),
            'attachments' => $request->files->get('attachments'),
        ];

        try {
            $thread = $this->ticketService->createTicketBase($ticketData);
        } catch (\Exception $e) {
            $this->addFlash('warning', $e->getMessage());
            return $this->redirect(!empty($referralURL) ? $referralURL : $this->generateUrl('helpdesk_member_ticket_collection'));
        }

        $ticket = $thread->getTicket();

        // Trigger ticket created event
        try {
            $event = new CoreWorkflowEvents\Ticket\Create();
            $event
                ->setTicket($ticket);

            $this->eventDispatcher->dispatch($event, 'uvdesk.automation.workflow.execute');
        } catch (\Exception $e) {
            // Skip Automation
        }

        if (! empty($thread)) {
            $ticket = $thread->getTicket();
            if (
                $request->request->get('customFields')
                || $request->files->get('customFields')
            ) {
                $this->ticketService->addTicketCustomFields($thread, $request->request->get('customFields'), $request->files->get('customFields'));
            }

            $this->addFlash('success', $this->translator->trans('Success ! Ticket has been created successfully.'));

            if ($this->userService->isAccessAuthorized('ROLE_ADMIN')) {
                return $this->redirect($this->generateUrl('helpdesk_member_ticket', ['ticketId' => $ticket->getId()]));
            }
        } else {
            $this->addFlash('warning', $this->translator->trans('Could not create ticket, invalid details.'));
        }

        return $this->redirect(!empty($referralURL) ? $referralURL : $this->generateUrl('helpdesk_member_ticket_collection'));
    }

    public function listTicketTypeCollection(Request $request)
    {
        if (! $this->userService->isAccessAuthorized('ROLE_AGENT_MANAGE_TICKET_TYPE')) {
            return $this->redirect($this->generateUrl('helpdesk_member_dashboard'));
        }

        return $this->render('@UVDeskCoreFramework/ticketTypeList.html.twig');
    }

    public function ticketType(Request $request)
    {
        if (! $this->userService->isAccessAuthorized('ROLE_AGENT_MANAGE_TICKET_TYPE')) {
            return $this->redirect($this->generateUrl('helpdesk_member_dashboard'));
        }

        $errorContext = [];
        $em = $this->entityManagerInterface;

        if ($id = $request->attributes->get('ticketTypeId')) {
            $type = $em->getRepository(CoreEntities\TicketType::class)->find($id);
            if (! $type) {
                $this->noResultFound();
            }
        } else {
            $type = new CoreEntities\TicketType();
        }

        if ($request->getMethod() == "POST") {
            $data = $request->request->all();
            $ticketType = $em->getRepository(CoreEntities\TicketType::class)->findOneByCode($data['code']);

            if (
                ! empty($ticketType)
                && $id != $ticketType->getId()
            ) {
                $this->addFlash('warning', sprintf('Error! Ticket type with same name already exist'));
            } else {
                $type->setCode(trim($data['code']));
                $type->setDescription(trim($data['description']));
                $type->setIsActive(isset($data['isActive']) ? 1 : 0);

                $em->persist($type);
                $em->flush();

                if (! $request->attributes->get('ticketTypeId')) {
                    $this->addFlash('success', $this->translator->trans('Success! Ticket type saved successfully.'));
                } else {
                    $this->addFlash('success', $this->translator->trans('Success! Ticket type updated successfully.'));
                }

                return $this->redirect($this->generateUrl('helpdesk_member_ticket_type_collection'));
            }
        }

        return $this->render('@UVDeskCoreFramework/ticketTypeAdd.html.twig', array(
            'type'   => $type,
            'errors' => json_encode($errorContext)
        ));
    }

    public function listTagCollection(Request $request)
    {
        if (!$this->userService->isAccessAuthorized('ROLE_AGENT_MANAGE_TAG')) {
            return $this->redirect($this->generateUrl('helpdesk_member_dashboard'));
        }

        $enabled_bundles = $this->getParameter('kernel.bundles');

        return $this->render('@UVDeskCoreFramework/supportTagList.html.twig', [
            'articlesEnabled' => in_array('UVDeskSupportCenterBundle', array_keys($enabled_bundles)),
        ]);
    }

    public function removeTicketTagXHR($tagId, Request $request)
    {
        if (!$this->userService->isAccessAuthorized('ROLE_AGENT_MANAGE_TAG')) {
            return $this->redirect($this->generateUrl('helpdesk_member_dashboard'));
        }

        $json = [];
        if ($request->getMethod() == "DELETE") {
            $em = $this->entityManagerInterface;
            $tag = $em->getRepository(CoreEntities\Tag::class)->find($tagId);

            if ($tag) {
                $em->remove($tag);
                $em->flush();
                $json['alertClass'] = 'success';
                $json['alertMessage'] = $this->translator->trans('Success ! Tag removed successfully.');
            }
        }

        $response = new Response(json_encode($json));
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    public function trashTicket(Request $request)
    {
        $ticketId = $request->attributes->get('ticketId');
        $entityManager = $this->entityManagerInterface;
        $ticket = $entityManager->getRepository(CoreEntities\Ticket::class)->find($ticketId);

        if (! $ticket) {
            $this->noResultFound();
        }

        $user = $this->userService->getSessionUser();

        // Proceed only if user has access to the resource
        if (false == $this->ticketService->isTicketAccessGranted($ticket, $user)) {
            throw new \Exception('Access Denied', 403);
        }

        if (! $ticket->getIsTrashed()) {
            $ticket->setIsTrashed(1);

            $entityManager->persist($ticket);
            $entityManager->flush();
        }

        // Trigger ticket delete event
        $event = new CoreWorkflowEvents\Ticket\Delete();
        $event
            ->setTicket($ticket);

        $this->eventDispatcher->dispatch($event, 'uvdesk.automation.workflow.execute');

        $this->addFlash('success', $this->translator->trans('Success ! Ticket moved to trash successfully.'));

        return $this->redirectToRoute('helpdesk_member_ticket_collection');
    }

    // Delete a ticket ticket permanently
    public function deleteTicket(Request $request)
    {
        $ticketId = $request->attributes->get('ticketId');
        $entityManager = $this->getDoctrine()->getManager();
        $ticket = $entityManager->getRepository(CoreEntities\Ticket::class)->find($ticketId);

        if (! $ticket) {
            $this->noResultFound();
        }

        $user = $this->userService->getSessionUser();
        // Proceed only if user has access to the resource
        if (false == $this->ticketService->isTicketAccessGranted($ticket, $user)) {
            throw new \Exception('Access Denied', 403);
        }

        $entityManager->remove($ticket);
        $entityManager->flush();

        $this->addFlash('success', $this->translator->trans('Success ! Success ! Ticket Id #' . $ticketId . ' has been deleted successfully.'));

        return $this->redirectToRoute('helpdesk_member_ticket_collection');
    }

    public function downloadZipAttachment(Request $request)
    {
        $threadId = $request->attributes->get('threadId');
        $attachmentRepository = $this->entityManagerInterface->getRepository(CoreEntities\Attachment::class);
        $threadRepository = $this->entityManagerInterface->getRepository(CoreEntities\Thread::class);

        $thread = $threadRepository->findOneById($threadId);
        if (!$thread) {
            $this->noResultFound();
        }

        $attachments = $attachmentRepository->findByThread($threadId);
        if (empty($attachments)) {
            $this->noResultFound();
        }

        $ticket = $thread->getTicket();
        $user = $this->userService->getSessionUser();

        // Proceed only if user has access to the resource
        if (false == $this->ticketService->isTicketAccessGranted($ticket, $user)) {
            throw new \Exception('Access Denied', 403);
        }

        // Create temp file in system temp directory
        $projectDir = $this->kernel->getProjectDir();
        $zipPath = sys_get_temp_dir() . '/' . uniqid('zip_') . '_attachments_' . $threadId . '.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            throw new \Exception('Cannot create zip file', 500);
        }

        if (count($attachments) > 0) {
            foreach ($attachments as $attach) {
                $filePath = $projectDir . '/public/' . ltrim($attach->getPath(), '/');
                if (file_exists($filePath)) {
                    // Use original filename as the name in the ZIP
                    $zip->addFile($filePath, $attach->getName());
                }
            }
        }

        $zip->close();

        // Use BinaryFileResponse for better handling of binary files
        $response = new BinaryFileResponse($zipPath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'attachments_' . $threadId . '.zip'
        );
        $response->headers->set('Content-Type', 'application/zip');
        $response->deleteFileAfterSend(true); // Delete the temp file after sending

        return $response;
    }

    public function downloadAttachment(Request $request)
    {
        $attachmentId = $request->attributes->get('attachmentId');
        $attachment = $this->entityManagerInterface->getRepository(CoreEntities\Attachment::class)->findOneById($attachmentId);

        if (empty($attachment)) {
            $this->noResultFound();
        }

        $thread = $attachment->getThread();

        if (! empty($thread)) {
            $ticket = $thread->getTicket();
            $user = $this->userService->getSessionUser();

            // Proceed only if user has access to the resource
            if (false == $this->ticketService->isTicketAccessGranted($ticket, $user)) {
                throw new \Exception('Access Denied', 403);
            }
        }

        $path = $this->kernel->getProjectDir() . "/public/" . $attachment->getPath();

        $response = new StreamedResponse(function () use ($path) {
            // Output the file content
            $stream = fopen($path, 'rb');
            fpassthru($stream);
            fclose($stream);
        });

        // Set headers
        $response->headers->set('Content-Type', $attachment->getContentType());
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $attachment->getName() . '"');
        $response->headers->set('Content-Length', filesize($path));

        return $response;
    }

    /**
     * If customer is playing with url and no result is found then what will happen
     * @return
     */
    protected function noResultFound()
    {
        throw new NotFoundHttpException('Not Found!');
    }
}
