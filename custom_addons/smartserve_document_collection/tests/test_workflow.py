from odoo.exceptions import ValidationError
from odoo.tests.common import TransactionCase


class TestSmartServeWorkflow(TransactionCase):

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.customer = cls.env['smartserve.customer'].create({
            'name': 'Workflow Test Customer',
            'email': 'workflow@example.com',
        })
        cls.template = cls.env['smartserve.service.template'].create({
            'name': 'Workflow Test Service',
            'document_template_ids': [
                (0, 0, {'name': 'Passport', 'required': True}),
                (0, 0, {'name': 'Photo', 'required': True}),
                (0, 0, {'name': 'Supporting Letter', 'required': False}),
            ],
        })

    def _request(self):
        return self.env['smartserve.document.request'].create({
            'title': 'Workflow Test',
            'customer_id': self.customer.id,
            'template_id': self.template.id,
            'service_name': self.template.name,
        })

    def _set_uploaded(self, requirement):
        requirement.write({
            'upload_status': 'uploaded',
            'review_status': 'pending',
            'uploaded_at': requirement.create_date,
        })

    def test_upload_progress_never_completes_request(self):
        request = self._request()
        request.with_context(smartserve_allow_state_write=True).state = 'waiting_documents'
        mandatory = request.required_document_ids.filtered('required')

        self._set_uploaded(mandatory[0])
        request._recalculate_upload_workflow()
        self.assertEqual(request.state, 'documents_partially_received')
        self.assertEqual(request.upload_status, 'partially_uploaded')

        self._set_uploaded(mandatory[1])
        request._recalculate_upload_workflow()
        self.assertEqual(request.state, 'documents_received')
        self.assertEqual(request.upload_status, 'uploaded')
        self.assertNotEqual(request.state, 'completed')
        self.assertTrue(request.activity_ids, 'A review activity should be scheduled for assigned staff.')

    def test_replacement_and_mandatory_review_workflow(self):
        request = self._request()
        mandatory = request.required_document_ids.filtered('required')
        request.with_context(smartserve_allow_state_write=True).state = 'documents_received'
        for requirement in mandatory:
            self._set_uploaded(requirement)
        request.action_start_review()

        mandatory[0].write({'rejection_reason': 'Unreadable scan'})
        mandatory[0].action_request_replacement()
        self.assertEqual(request.state, 'additional_documents_required')
        self.assertEqual(mandatory[0].upload_status, 'not_uploaded')

        self._set_uploaded(mandatory[0])
        request._recalculate_upload_workflow()
        self.assertEqual(request.state, 'documents_received')
        request.action_start_review()
        mandatory.action_approve()
        self.assertEqual(request.state, 'ready_for_processing')

    def test_optional_documents_do_not_block_processing(self):
        request = self._request()
        mandatory = request.required_document_ids.filtered('required')
        for requirement in mandatory:
            self._set_uploaded(requirement)
            requirement.action_approve()
        request.with_context(smartserve_allow_state_write=True).state = 'document_review'
        request.action_mark_documents_approved()
        self.assertEqual(request.state, 'ready_for_processing')

    def test_completion_requires_result_delivery_and_notes(self):
        request = self._request()
        mandatory = request.required_document_ids.filtered('required')
        for requirement in mandatory:
            self._set_uploaded(requirement)
            requirement.action_approve()
        request.with_context(smartserve_allow_state_write=True).state = 'ready_for_processing'
        request.action_mark_service_approved()

        with self.assertRaises(ValidationError):
            request.action_mark_completed()

        request.action_mark_result_received()
        request.action_deliver_to_customer()
        request.completion_notes = 'Final result delivered and confirmed by customer.'
        request.action_mark_completed()
        self.assertEqual(request.state, 'completed')
        self.assertTrue(request.upload_revoked)

    def test_arbitrary_state_transition_is_blocked(self):
        request = self._request()
        with self.assertRaises(ValidationError):
            request.state = 'completed'

    def test_role_dashboard_metrics_and_action_center(self):
        request = self._request()
        request.with_context(smartserve_allow_state_write=True).write({
            'state': 'document_review',
            'assigned_user_id': self.env.user.id,
            'priority': '2',
        })
        dashboard = self.env.ref(
            'smartserve_document_collection.smartserve_workflow_dashboard_default'
        )
        values = dashboard.read([
            'pending_review_count',
            'my_pending_review_count',
            'my_active_count',
            'action_request_ids',
            'team_request_ids',
            'user_name',
        ])[0]

        self.assertGreaterEqual(values['pending_review_count'], 1)
        self.assertGreaterEqual(values['my_pending_review_count'], 1)
        self.assertGreaterEqual(values['my_active_count'], 1)
        self.assertIn(request.id, values['action_request_ids'])
        self.assertIn(request.id, values['team_request_ids'])
        self.assertEqual(values['user_name'], self.env.user.name)

        action = dashboard.action_open_my_review()
        self.assertEqual(action['res_model'], 'smartserve.document.request')
        self.assertIn(('assigned_user_id', '=', self.env.user.id), action['domain'])
