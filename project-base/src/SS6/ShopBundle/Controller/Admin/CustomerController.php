<?php

namespace SS6\ShopBundle\Controller\Admin;

use DateTime;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use SS6\ShopBundle\Form\Admin\Customer\CustomerFormType;
use SS6\ShopBundle\Model\AdminNavigation\MenuItem;
use SS6\ShopBundle\Model\Customer\CustomerData;
use SS6\ShopBundle\Model\Customer\User;
use SS6\ShopBundle\Model\Order\Order;
use SS6\ShopBundle\Model\Pricing\Group\PricingGroup;
use SS6\ShopBundle\Model\Grid\QueryBuilderDataSource;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;

class CustomerController extends Controller {

	/**
	 * @Route("/customer/edit/{id}", requirements={"id" = "\d+"})
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param int $id
	 */
	public function editAction(Request $request, $id) {
		$flashMessageSender = $this->get('ss6.shop.flash_message.sender.admin');
		/* @var $flashMessageSender \SS6\ShopBundle\Model\FlashMessage\FlashMessageSender */
		$customerEditFacade = $this->get('ss6.shop.customer.customer_edit_facade');
		/* @var $customerEditFacade \SS6\ShopBundle\Model\Customer\CustomerEditFacade */
		$customerFormTypeFactory = $this->get('ss6.shop.form.admin.customer_form_type_factory');
		/* @var $customerFormTypeFactory \SS6\ShopBundle\Form\Admin\Payment\CustomerFormTypeFactory */

		$user = $customerEditFacade->getUserById($id);
		$form = $this->createForm($customerFormTypeFactory->create(CustomerFormType::SCENARIO_EDIT, $user));

		try {
			$customerData = new CustomerData();

			if (!$form->isSubmitted()) {
				$customerData->setFromEntity($user);
			}

			$form->setData($customerData);
			$form->handleRequest($request);

			if ($form->isValid()) {
				$customerEditFacade->editByAdmin($id, $customerData);

				$flashMessageSender->addSuccessTwig('Byl upraven zákazník <strong><a href="{{ url }}">{{ name }}</a></strong>', array(
					'name' => $user->getFullName(),
					'url' => $this->generateUrl('admin_customer_edit', array('id' => $user->getId())),
				));
				return $this->redirect($this->generateUrl('admin_customer_list'));
			}
		} catch (\SS6\ShopBundle\Model\Customer\Exception\DuplicateEmailException $e) {
			$form->get('email')->addError(new FormError('V databázi se již nachází zákazník s tímto e-mailem'));
		}

		if ($form->isSubmitted() && !$form->isValid()) {
			$flashMessageSender->addErrorTwig('Prosím zkontrolujte si správnost vyplnění všech údajů');
		}

		$breadcrumb = $this->get('ss6.shop.admin_navigation.breadcrumb');
		/* @var $breadcrumb \SS6\ShopBundle\Model\AdminNavigation\Breadcrumb */
		$breadcrumb->replaceLastItem(new MenuItem('Editace zákazníka - ' . $user->getFullName()));

		return $this->render('@SS6Shop/Admin/Content/Customer/edit.html.twig', array(
			'form' => $form->createView(),
			'user' => $user,
		));
	}

	/**
	 * @Route("/customer/list/")
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 */
	public function listAction() {
		$administratorGridFacade = $this->get('ss6.shop.administrator.administrator_grid_facade');
		/* @var $administratorGridFacade \SS6\ShopBundle\Model\Administrator\AdministratorGridFacade */
		$administrator = $this->getUser();
		/* @var $administrator \SS6\ShopBundle\Model\Administrator\Administrator */
		$gridFactory = $this->get('ss6.shop.grid.factory');
		/* @var $gridFactory \SS6\ShopBundle\Model\Grid\GridFactory */
		$selectedDomain = $this->get('ss6.shop.domain.selected_domain');
		/* @var $selectedDomain \SS6\ShopBundle\Model\Domain\SelectedDomain */

		$queryBuilder = $this->getDoctrine()->getManager()->createQueryBuilder();
		/* @var $queryBuilder \Doctrine\ORM\QueryBuilder */
		$queryBuilder
			->select('
				u.id,
				u.email,
				MAX(pg.name) AS pricingGroup,
				MAX(ba.city) city,
				MAX(ba.telephone) telephone,
				MAX(CASE WHEN ba.companyName IS NOT NULL
						THEN ba.companyName
						ELSE CONCAT(u.firstName, \' \', u.lastName)
					END) AS name,
				COUNT(o.id) ordersCount,
				SUM(o.totalPriceWithVat) ordersSumPrice,
				COALESCE(SUM(o.totalPriceWithVat), 0) ordersSumPriceOrder,
				MAX(o.createdAt) lastOrderAt,
				COALESCE(MAX(o.createdAt), :emptyDateTime) lastOrderAtOrder')
			->from(User::class, 'u')
			->where('u.domainId = :selectedDomainId')
			->setParameter('selectedDomainId', $selectedDomain->getId())
			->leftJoin('u.billingAddress', 'ba')
			->leftJoin(Order::class, 'o', 'WITH', 'o.customer = u.id')
			->leftJoin(PricingGroup::class, 'pg', 'WITH', 'pg.id = u.pricingGroup')
			->groupBy('u.id');
		$queryBuilder->setParameter('emptyDateTime', new DateTime('@0'));
		$dataSource = new QueryBuilderDataSource($queryBuilder, 'u.id');

		$grid = $gridFactory->create('customerList', $dataSource);
		$grid->allowPaging();
		$grid->setDefaultOrder('name');

		$grid->addColumn('name', 'name', 'Jméno', true);
		$grid->addColumn('city', 'city', 'Město', true);
		$grid->addColumn('telephone', 'telephone', 'Telefon', true);
		$grid->addColumn('email', 'u.email', 'Email', true);
		$grid->addColumn('pricingGroup', 'pricingGroup', 'Cenová skupina', true);
		$grid->addColumn('orders_count', 'ordersCount', 'Počet objednávek', true)->setClassAttribute('text-right');
		$grid->addColumn('orders_sum_price', 'ordersSumPrice', 'Hodnota objednávek', 'ordersSumPriceOrder')
			->setClassAttribute('text-right');
		$grid->addColumn('last_order_at', 'lastOrderAt', 'Poslední objednávka', 'lastOrderAtOrder')
			->setClassAttribute('text-right');

		$grid->setActionColumnClassAttribute('table-col table-col-10');
		$grid->addActionColumn('edit', 'Upravit', 'admin_customer_edit', array('id' => 'id'));
		$grid->addActionColumn('delete', 'Smazat', 'admin_customer_delete', array('id' => 'id'))
			->setConfirmMessage('Opravdu chcete odstranit tohoto zákazníka?');

		$administratorGridFacade->restoreAndRememberGridLimit($administrator, $grid);

		return $this->render('@SS6Shop/Admin/Content/Customer/list.html.twig', array(
			'gridView' => $grid->createView(),
		));
	}

	/**
	 * @Route("/customer/new/")
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 */
	public function newAction(Request $request) {
		$flashMessageSender = $this->get('ss6.shop.flash_message.sender.admin');
		/* @var $flashMessageSender \SS6\ShopBundle\Model\FlashMessage\FlashMessageSender */
		$customerFormTypeFactory = $this->get('ss6.shop.form.admin.customer_form_type_factory');
		/* @var $customerFormTypeFactory \SS6\ShopBundle\Form\Admin\Payment\CustomerFormTypeFactory */

		$form = $this->createForm(
			$customerFormTypeFactory->create(CustomerFormType::SCENARIO_CREATE),
			null,
			array('validation_groups' => array('Default', 'create'))
		);

		try {
			$customerData = new CustomerData();

			$form->setData($customerData);
			$form->handleRequest($request);

			if ($form->isValid()) {
				$customerData = $form->getData();
				$customerEditFacade = $this->get('ss6.shop.customer.customer_edit_facade');
				/* @var $customerEditFacade \SS6\ShopBundle\Model\Customer\CustomerEditFacade */

				$user = $customerEditFacade->create($customerData);

				$flashMessageSender->addSuccessTwig('Byl vytvořen zákazník <strong><a href="{{ url }}">{{ name }}</a></strong>', array(
					'name' => $user->getFullName(),
					'url' => $this->generateUrl('admin_customer_edit', array('id' => $user->getId())),
				));
				return $this->redirect($this->generateUrl('admin_customer_list'));
			}
		} catch (\SS6\ShopBundle\Model\Customer\Exception\DuplicateEmailException $e) {
			$form->get('userData')->get('email')->addError(new FormError('V databázi se již nachází zákazník s tímto e-mailem'));
		}

		if ($form->isSubmitted() && !$form->isValid()) {
			$flashMessageSender->addErrorTwig('Prosím zkontrolujte si správnost vyplnění všech údajů');
		}

		return $this->render('@SS6Shop/Admin/Content/Customer/new.html.twig', array(
			'form' => $form->createView(),
		));
	}

	/**
	 * @Route("/customer/delete/{id}", requirements={"id" = "\d+"})
	 * @param int $id
	 */
	public function deleteAction($id) {
		$flashMessageSender = $this->get('ss6.shop.flash_message.sender.admin');
		/* @var $flashMessageSender \SS6\ShopBundle\Model\FlashMessage\FlashMessageSender */
		$customerEditFacade = $this->get('ss6.shop.customer.customer_edit_facade');
		/* @var $customerEditFacade \SS6\ShopBundle\Model\Customer\CustomerEditFacade */

		$fullName = $customerEditFacade->getUserById($id)->getFullName();
		$customerEditFacade->delete($id);
		$flashMessageSender->addSuccessTwig('Zákazník <strong>{{ name }}</strong> byl smazán', array(
			'name' => $fullName,
		));

		return $this->redirect($this->generateUrl('admin_customer_list'));
	}

}
