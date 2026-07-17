from odoo.exceptions import AccessError, ValidationError
from odoo.tests.common import TransactionCase


class TestSmartServeUserManagement(TransactionCase):

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        Users = cls.env['res.users'].sudo()
        cls.team_a = cls.env['smartserve.team'].sudo().create({'name': 'Team A'})
        cls.team_b = cls.env['smartserve.team'].sudo().create({'name': 'Team B'})
        cls.manager = Users.create({
            'name': 'Test Manager', 'login': 'ss-manager@example.test',
            'smartserve_role': 'manager', 'smartserve_team_id': cls.team_a.id,
        })
        cls.employee_a = Users.create({
            'name': 'Employee A', 'login': 'ss-employee-a@example.test',
            'smartserve_role': 'employee', 'smartserve_team_id': cls.team_a.id,
        })
        cls.employee_b = Users.create({
            'name': 'Employee B', 'login': 'ss-employee-b@example.test',
            'smartserve_role': 'employee', 'smartserve_team_id': cls.team_b.id,
        })
        cls.customer = cls.env['smartserve.customer'].sudo().create({'name': 'Permissions Customer'})
        request_model = cls.env['smartserve.document.request'].sudo()
        cls.request_a = request_model.create({
            'title': 'Team A Request', 'customer_id': cls.customer.id,
            'service_name': 'Test Service', 'assigned_user_id': cls.employee_a.id,
        })
        cls.request_b = request_model.create({
            'title': 'Team B Request', 'customer_id': cls.customer.id,
            'service_name': 'Test Service', 'assigned_user_id': cls.employee_b.id,
        })

    def test_role_groups_and_audit_are_applied(self):
        self.assertTrue(self.manager.has_group('smartserve_document_collection.group_smartserve_manager'))
        self.assertTrue(self.employee_a.has_group('smartserve_document_collection.group_smartserve_staff'))
        self.assertFalse(self.employee_a.smartserve_can_view_all_requests)
        self.assertTrue(self.env['smartserve.user.audit'].sudo().search_count([
            ('user_id', '=', self.employee_a.id), ('event_type', '=', 'created')
        ]))

    def test_employee_and_manager_request_visibility(self):
        employee_requests = self.env['smartserve.document.request'].with_user(self.employee_a).search([])
        self.assertIn(self.request_a, employee_requests)
        self.assertNotIn(self.request_b, employee_requests)

        manager_requests = self.env['smartserve.document.request'].with_user(self.manager).search([])
        self.assertIn(self.request_a, manager_requests)
        self.assertNotIn(self.request_b, manager_requests)

    def test_optional_permission_is_enforced_server_side(self):
        with self.assertRaises(AccessError):
            self.env['smartserve.customer'].with_user(self.employee_a).create({'name': 'Not Allowed'})

        self.employee_a.sudo().write({'smartserve_can_create_customers': True})
        customer = self.env['smartserve.customer'].with_user(self.employee_a).create({'name': 'Allowed'})
        self.assertEqual(customer.created_by_id, self.employee_a)

    def test_last_active_administrator_is_protected(self):
        candidate = self.env['res.users'].sudo().create({
            'name': 'Safety Administrator', 'login': 'ss-safety-admin@example.test',
            'smartserve_role': 'administrator',
        })
        other_admins = self.env['res.users'].sudo().search([
            ('active', '=', True), ('smartserve_role', '=', 'administrator'), ('id', '!=', candidate.id)
        ])
        other_admins.sudo().write({'smartserve_role': 'employee'})
        with self.assertRaises(ValidationError):
            candidate.sudo().write({'active': False})

    def test_dashboard_user_counts(self):
        dashboard = self.env.ref('smartserve_document_collection.smartserve_workflow_dashboard_default')
        values = dashboard.read(['active_users_count', 'managers_count', 'employees_count'])[0]
        self.assertGreaterEqual(values['active_users_count'], 3)
        self.assertGreaterEqual(values['managers_count'], 1)
        self.assertGreaterEqual(values['employees_count'], 2)
