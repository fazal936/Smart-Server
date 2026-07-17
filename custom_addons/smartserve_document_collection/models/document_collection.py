import secrets
from datetime import timedelta

from odoo import api, fields, models, _
from odoo.exceptions import AccessError, UserError, ValidationError

from ..services.communication import get_communication_provider
from ..services.storage import get_storage_provider


REQUEST_STATES = [
    ('draft', 'Draft'),
    ('waiting_documents', 'Waiting for Documents'),
    ('documents_partially_received', 'Documents Partially Received'),
    ('documents_received', 'Documents Received'),
    ('document_review', 'Under Document Review'),
    ('additional_documents_required', 'Additional Documents Required'),
    ('ready_for_processing', 'Ready for Processing'),
    ('submitted_to_authority', 'Submitted to Authority'),
    ('external_processing', 'External Processing'),
    ('action_required', 'Action Required'),
    ('service_approved', 'Service Approved'),
    ('completed', 'Completed'),
    ('on_hold', 'On Hold'),
    ('cancelled', 'Cancelled'),
    ('rejected', 'Rejected'),
    ('expired', 'Expired'),
]

UPLOAD_STATES = [
    ('not_uploaded', 'Not Uploaded'),
    ('partially_uploaded', 'Partially Uploaded'),
    ('uploaded', 'Uploaded'),
]

REVIEW_STATES = [
    ('pending', 'Pending'),
    ('approved', 'Approved'),
    ('rejected', 'Rejected'),
    ('replacement_required', 'Replacement Required'),
]

TERMINAL_STATES = ('completed', 'cancelled', 'rejected', 'expired')


class SmartServeCustomer(models.Model):
    _name = 'smartserve.customer'
    _description = 'SmartServe Customer'
    _order = 'name'

    name = fields.Char(required=True)
    company = fields.Char()
    mobile = fields.Char()
    email = fields.Char()
    emirates_id = fields.Char()
    passport = fields.Char()
    notes = fields.Text()
    created_by_id = fields.Many2one('res.users', default=lambda self: self.env.user, readonly=True)
    request_ids = fields.One2many('smartserve.document.request', 'customer_id')
    request_count = fields.Integer(compute='_compute_request_count')

    @api.depends('request_ids')
    def _compute_request_count(self):
        for customer in self:
            customer.request_count = len(customer.request_ids)

    @api.model_create_multi
    def create(self, vals_list):
        user = self.env.user
        if (user.smartserve_role == 'employee' and
                not user.smartserve_can_create_customers and not self.env.is_superuser()):
            raise AccessError(_('You do not have permission to create customers.'))
        return super().create(vals_list)


class SmartServeServiceTemplate(models.Model):
    _name = 'smartserve.service.template'
    _description = 'SmartServe Service Template'
    _order = 'name'

    name = fields.Char(required=True)
    active = fields.Boolean(default=True)
    whatsapp_template = fields.Char()
    due_days = fields.Integer(default=7)
    folder_naming_rule = fields.Char(default='{customer_name}-{request_ref}')
    upload_rule = fields.Text()
    requires_authority_processing = fields.Boolean(default=True)
    document_template_ids = fields.One2many(
        'smartserve.service.template.document', 'template_id', string='Required Documents'
    )

    def _check_smartserve_template_permission(self):
        user = self.env.user
        if (user.smartserve_role and not user.smartserve_can_edit_templates and
                not user.has_group('smartserve_document_collection.group_smartserve_admin') and
                not self.env.is_superuser()):
            raise AccessError(_('You do not have permission to edit service templates.'))

    @api.model_create_multi
    def create(self, vals_list):
        self._check_smartserve_template_permission()
        return super().create(vals_list)

    def write(self, vals):
        self._check_smartserve_template_permission()
        return super().write(vals)

    def unlink(self):
        self._check_smartserve_template_permission()
        return super().unlink()


class SmartServeServiceTemplateDocument(models.Model):
    _name = 'smartserve.service.template.document'
    _description = 'SmartServe Service Template Document'
    _order = 'sequence, id'

    sequence = fields.Integer(default=10)
    template_id = fields.Many2one('smartserve.service.template', required=True, ondelete='cascade')
    name = fields.Char(required=True)
    description = fields.Text()
    required = fields.Boolean(default=True)
    allowed_extensions = fields.Char(default='pdf,jpg,jpeg,png')
    max_size_mb = fields.Integer(default=10)


class SmartServeAuthority(models.Model):
    _name = 'smartserve.authority'
    _description = 'External Authority or Organization'
    _order = 'sequence, name'

    sequence = fields.Integer(default=10)
    name = fields.Char(required=True)
    code = fields.Char()
    authority_type = fields.Char(string='Authority Type')
    active = fields.Boolean(default=True)
    notes = fields.Text()

    _sql_constraints = [
        ('authority_name_unique', 'unique(name)', 'Authority name must be unique.'),
    ]


class SmartServeDocumentRequest(models.Model):
    _name = 'smartserve.document.request'
    _description = 'SmartServe Service Request'
    _inherit = ['mail.thread', 'mail.activity.mixin']
    _order = 'priority desc, due_date, create_date desc'

    name = fields.Char(default='New', readonly=True, copy=False, tracking=True)
    title = fields.Char(required=True, tracking=True)
    customer_id = fields.Many2one('smartserve.customer', required=True, ondelete='restrict', tracking=True)
    customer_name = fields.Char(string='Customer Name', related='customer_id.name', store=True)
    email = fields.Char(related='customer_id.email', store=True)
    mobile = fields.Char(related='customer_id.mobile', store=True)
    template_id = fields.Many2one('smartserve.service.template', string='Service Template', ondelete='restrict')
    service_name = fields.Char(required=True, tracking=True)
    assigned_user_id = fields.Many2one('res.users', default=lambda self: self.env.user, tracking=True)
    priority = fields.Selection([
        ('0', 'Normal'), ('1', 'Low'), ('2', 'High'), ('3', 'Urgent')
    ], default='0', required=True, tracking=True)
    request_date = fields.Date(default=fields.Date.context_today, required=True)
    due_date = fields.Date(string='Internal Due Date', tracking=True)
    internal_notes = fields.Text()
    state = fields.Selection(REQUEST_STATES, default='draft', required=True, index=True, tracking=True)
    kanban_stage = fields.Selection([
        ('documents', 'Document Collection'),
        ('review', 'Document Review'),
        ('processing', 'Ready / Internal Processing'),
        ('authority', 'Authority Processing'),
        ('action', 'Action / Hold'),
        ('delivery', 'Approval & Delivery'),
        ('closed', 'Closed'),
    ], compute='_compute_operational_fields', store=True, index=True)
    upload_status = fields.Selection(UPLOAD_STATES, compute='_compute_document_progress', store=True, index=True)
    upload_progress = fields.Integer(compute='_compute_document_progress', store=True)
    document_progress = fields.Char(compute='_compute_document_progress', store=True)
    next_action = fields.Char(compute='_compute_operational_fields', store=True)
    days_waiting = fields.Integer(compute='_compute_days_waiting')
    is_overdue = fields.Boolean(compute='_compute_is_overdue', search='_search_is_overdue')
    last_activity_date = fields.Datetime(readonly=True, index=True)
    state_before_hold = fields.Selection(REQUEST_STATES, copy=False, readonly=True)

    upload_token = fields.Char(default=lambda self: self._generate_token(), readonly=True, copy=False, index=True)
    upload_expires_at = fields.Datetime(default=lambda self: self._default_expiry(), required=True)
    upload_revoked = fields.Boolean(default=False)
    allow_multiple_uploads = fields.Boolean(default=True)
    sharepoint_folder_id = fields.Char(readonly=True)
    sharepoint_folder_url = fields.Char(readonly=True)
    communication_status = fields.Selection([
        ('not_sent', 'Not Sent'), ('not_configured', 'Provider Not Configured'),
        ('queued', 'Queued'), ('sent', 'Sent'), ('failed', 'Failed'),
    ], default='not_sent', required=True)

    authority_id = fields.Many2one('smartserve.authority', tracking=True)
    authority_name = fields.Char(string='Authority Name', related='authority_id.name', store=True, readonly=True)
    authority_type = fields.Char(related='authority_id.authority_type', store=True, readonly=True)
    external_reference = fields.Char(tracking=True)
    submission_date = fields.Date(tracking=True)
    expected_completion_date = fields.Date(tracking=True)
    external_status = fields.Selection([
        ('not_submitted', 'Not Submitted'), ('submitted', 'Submitted'),
        ('in_process', 'In Process'), ('action_required', 'Action Required'),
        ('approved', 'Approved'), ('rejected', 'Rejected'), ('completed', 'Completed'),
    ], default='not_submitted', tracking=True)
    external_notes = fields.Text()
    company_id = fields.Many2one('res.company', default=lambda self: self.env.company, required=True)
    currency_id = fields.Many2one(related='company_id.currency_id', store=True)
    government_fee = fields.Monetary(currency_field='currency_id')
    payment_status = fields.Selection([
        ('not_required', 'Not Required'), ('pending', 'Pending'),
        ('partially_paid', 'Partially Paid'), ('paid', 'Paid'), ('refunded', 'Refunded'),
    ], default='not_required', tracking=True)
    payment_reference = fields.Char()
    approval_date = fields.Date(readonly=True)
    result_received_date = fields.Date(readonly=True)
    final_result_url = fields.Char(string='Final File / SharePoint Link')
    result_storage_file_id = fields.Char(string='Final Storage File ID')
    delivered_to_customer = fields.Boolean(default=False, readonly=True)
    final_delivery_date = fields.Date(readonly=True)
    completion_notes = fields.Text()
    completion_override = fields.Boolean(string='Manager Completion Override')
    completion_override_reason = fields.Text()
    completed_date = fields.Datetime(readonly=True, index=True)

    required_document_ids = fields.One2many('smartserve.required.document', 'request_id', string='Documents')
    uploaded_document_ids = fields.One2many('smartserve.uploaded.document', 'request_id', string='Uploaded Documents')
    timeline_event_ids = fields.One2many('smartserve.request.activity', 'request_id', string='Timeline')
    upload_url = fields.Char(compute='_compute_upload_url')
    required_count = fields.Integer(compute='_compute_counts')
    uploaded_count = fields.Integer(compute='_compute_counts')
    pending_count = fields.Integer(compute='_compute_counts')
    rejected_count = fields.Integer(compute='_compute_counts')

    _sql_constraints = [('upload_token_unique', 'unique(upload_token)', 'Upload token must be unique.')]

    _TRANSITIONS = {
        'draft': {'waiting_documents', 'cancelled'},
        'waiting_documents': {'documents_partially_received', 'documents_received', 'on_hold', 'cancelled', 'expired'},
        'documents_partially_received': {'documents_received', 'additional_documents_required', 'on_hold', 'cancelled', 'expired'},
        'documents_received': {'document_review', 'additional_documents_required', 'on_hold', 'cancelled'},
        'document_review': {'additional_documents_required', 'ready_for_processing', 'rejected', 'on_hold', 'cancelled'},
        'additional_documents_required': {'documents_partially_received', 'documents_received', 'document_review', 'on_hold', 'cancelled'},
        'ready_for_processing': {'submitted_to_authority', 'external_processing', 'service_approved', 'action_required', 'on_hold', 'cancelled'},
        'submitted_to_authority': {'external_processing', 'action_required', 'service_approved', 'rejected', 'on_hold', 'cancelled'},
        'external_processing': {'action_required', 'service_approved', 'rejected', 'on_hold', 'cancelled'},
        'action_required': {'additional_documents_required', 'ready_for_processing', 'submitted_to_authority', 'external_processing', 'service_approved', 'on_hold', 'cancelled'},
        'service_approved': {'completed', 'action_required', 'on_hold', 'cancelled'},
        'on_hold': set(REQUEST_STATES[i][0] for i in range(len(REQUEST_STATES))) - {'on_hold'},
        'cancelled': {'draft', 'waiting_documents', 'documents_received', 'document_review', 'ready_for_processing'},
        'rejected': {'draft', 'waiting_documents', 'document_review'},
        'expired': {'waiting_documents'},
        'completed': {'ready_for_processing', 'service_approved'},
    }

    def init(self):
        """Map legacy workflow/upload values during install and every module upgrade."""
        self.env.cr.execute("SELECT to_regclass('smartserve_document_request')")
        if self.env.cr.fetchone()[0]:
            self.env.cr.execute("""
                UPDATE smartserve_document_request
                   SET state = CASE state
                       WHEN 'waiting' THEN 'waiting_documents'
                       WHEN 'submitted' THEN 'documents_received'
                       WHEN 'review' THEN 'document_review'
                       WHEN 'revoked' THEN 'cancelled'
                       ELSE state END
                 WHERE state IN ('waiting', 'submitted', 'review', 'revoked')
            """)
        self.env.cr.execute("SELECT to_regclass('smartserve_required_document')")
        if self.env.cr.fetchone()[0]:
            self.env.cr.execute("""
                UPDATE smartserve_required_document
                   SET upload_status = 'not_uploaded'
                 WHERE upload_status = 'missing'
            """)

    @api.model_create_multi
    def create(self, vals_list):
        user = self.env.user
        if (user.smartserve_role and not user.smartserve_can_create_requests and
                not user.has_group('smartserve_document_collection.group_smartserve_admin') and
                not self.env.is_superuser()):
            raise AccessError(_('You do not have permission to create requests.'))
        sequence = self.env['ir.sequence']
        for vals in vals_list:
            if vals.get('name', 'New') == 'New':
                vals['name'] = sequence.next_by_code('smartserve.document.request') or 'New'
        records = super().create(vals_list)
        for record in records:
            record._apply_template_documents()
            record._log_activity('request_created', _('Request created.'))
        return records

    def write(self, vals):
        if ('assigned_user_id' in vals and self.env.user.smartserve_role and
                not self.env.user.smartserve_can_reassign_requests and
                not self.env.user.has_group('smartserve_document_collection.group_smartserve_admin') and
                not self.env.is_superuser()):
            raise AccessError(_('You do not have permission to assign or reassign requests.'))
        previous_references = {rec.id: rec.external_reference for rec in self} if 'external_reference' in vals else {}
        if 'state' in vals and not self.env.context.get('smartserve_allow_state_write'):
            for request_record in self:
                if vals['state'] != request_record.state and vals['state'] not in self._TRANSITIONS.get(request_record.state, set()):
                    raise ValidationError(_('Invalid workflow transition from %s to %s.') % (
                        dict(REQUEST_STATES).get(request_record.state), dict(REQUEST_STATES).get(vals['state'])
                    ))
        result = super().write(vals)
        if 'external_reference' in vals and vals.get('external_reference'):
            for request_record in self.filtered(lambda rec: previous_references.get(rec.id) != rec.external_reference):
                request_record._log_activity(
                    'external_reference_added',
                    _('External reference added: %s') % request_record.external_reference,
                )
        return result

    @api.onchange('template_id')
    def _onchange_template_id(self):
        if self.template_id:
            self.service_name = self.template_id.name
            self.title = self.title or self.template_id.name
            self.due_date = fields.Date.today() + timedelta(days=self.template_id.due_days or 0)
            self.required_document_ids = [(5, 0, 0)] + self._template_document_commands()

    def _template_document_commands(self):
        self.ensure_one()
        return [(0, 0, {
            'sequence': doc.sequence, 'name': doc.name, 'description': doc.description,
            'required': doc.required, 'allowed_extensions': doc.allowed_extensions,
            'max_size_mb': doc.max_size_mb,
        }) for doc in self.template_id.document_template_ids]

    @api.model
    def _generate_token(self):
        return secrets.token_urlsafe(32)

    @api.model
    def _default_expiry(self):
        days = int(self.env['ir.config_parameter'].sudo().get_param('smartserve.upload_link_expiry_days', '14'))
        return fields.Datetime.now() + timedelta(days=days)

    @api.depends('upload_token')
    def _compute_upload_url(self):
        base_url = self.env['ir.config_parameter'].sudo().get_param('web.base.url', '')
        for rec in self:
            rec.upload_url = f'{base_url}/upload/{rec.upload_token}' if rec.upload_token else False

    @api.depends('required_document_ids.upload_status', 'required_document_ids.required')
    def _compute_document_progress(self):
        for rec in self:
            mandatory = rec.required_document_ids.filtered('required')
            uploaded = mandatory.filtered(lambda d: d.upload_status == 'uploaded')
            total = len(mandatory)
            count = len(uploaded)
            rec.upload_progress = round((count / total) * 100) if total else 100
            rec.document_progress = _('%s/%s mandatory') % (count, total)
            rec.upload_status = 'uploaded' if not total or count == total else ('partially_uploaded' if count else 'not_uploaded')

    @api.depends('state', 'payment_status', 'delivered_to_customer', 'result_received_date')
    def _compute_operational_fields(self):
        stage_map = {
            'draft': 'documents', 'waiting_documents': 'documents',
            'documents_partially_received': 'documents', 'documents_received': 'review',
            'document_review': 'review', 'additional_documents_required': 'review',
            'ready_for_processing': 'processing', 'submitted_to_authority': 'authority',
            'external_processing': 'authority', 'action_required': 'action', 'on_hold': 'action',
            'service_approved': 'delivery', 'completed': 'closed', 'cancelled': 'closed',
            'rejected': 'closed', 'expired': 'closed',
        }
        next_map = {
            'draft': _('Send document request'),
            'waiting_documents': _('Waiting for customer documents'),
            'documents_partially_received': _('Waiting for remaining documents'),
            'documents_received': _('Review uploaded documents'),
            'document_review': _('Approve or return documents'),
            'additional_documents_required': _('Waiting for replacement/additional documents'),
            'ready_for_processing': _('Submit to authority or begin processing'),
            'submitted_to_authority': _('Follow up with authority'),
            'external_processing': _('Follow up with authority'),
            'action_required': _('Resolve required action'),
            'service_approved': _('Receive and deliver final result'),
            'on_hold': _('Review hold and reopen request'),
            'completed': _('Closed'), 'cancelled': _('Closed'), 'rejected': _('Closed'), 'expired': _('Renew upload request'),
        }
        for rec in self:
            rec.kanban_stage = stage_map.get(rec.state, 'action')
            if rec.payment_status in ('pending', 'partially_paid') and rec.state not in TERMINAL_STATES:
                rec.next_action = _('Collect payment')
            elif rec.state == 'service_approved' and rec.result_received_date and not rec.delivered_to_customer:
                rec.next_action = _('Deliver completed result')
            else:
                rec.next_action = next_map.get(rec.state, _('Review request'))

    def _compute_days_waiting(self):
        today = fields.Date.context_today(self)
        for rec in self:
            start = rec.request_date or (rec.create_date.date() if rec.create_date else today)
            rec.days_waiting = max((today - start).days, 0)

    def _compute_is_overdue(self):
        today = fields.Date.context_today(self)
        for rec in self:
            rec.is_overdue = bool(rec.due_date and rec.due_date < today and rec.state not in TERMINAL_STATES)

    def _search_is_overdue(self, operator, value):
        domain = [('due_date', '<', fields.Date.context_today(self)), ('state', 'not in', TERMINAL_STATES)]
        return domain if (operator in ('=', '==') and value) or (operator == '!=' and not value) else ['!'] + domain

    @api.depends('required_document_ids.upload_status', 'required_document_ids.review_status', 'uploaded_document_ids.review_status')
    def _compute_counts(self):
        for rec in self:
            rec.required_count = len(rec.required_document_ids)
            rec.uploaded_count = len(rec.required_document_ids.filtered(lambda d: d.upload_status == 'uploaded'))
            rec.pending_count = len(rec.required_document_ids.filtered(lambda d: d.required and (d.upload_status != 'uploaded' or d.review_status != 'approved')))
            rec.rejected_count = len(rec.required_document_ids.filtered(lambda d: d.review_status in ('rejected', 'replacement_required')))

    def _apply_template_documents(self):
        for rec in self:
            if not rec.required_document_ids and rec.template_id:
                rec.required_document_ids = rec._template_document_commands()

    def is_upload_link_usable(self):
        self.ensure_one()
        usable_states = ('waiting_documents', 'documents_partially_received', 'documents_received',
                         'document_review', 'additional_documents_required', 'action_required')
        return (self.state in usable_states and not self.upload_revoked
                and self.upload_expires_at >= fields.Datetime.now()
                and (self.allow_multiple_uploads or self.upload_status == 'not_uploaded'))

    def _set_workflow_state(self, new_state, description, event_type, allowed_from=None, notes=None):
        for rec in self:
            old_state = rec.state
            valid_from = set(allowed_from or self._TRANSITIONS.get(old_state, set()))
            if allowed_from is not None and old_state not in valid_from:
                raise ValidationError(_('This action is not available from %s.') % dict(REQUEST_STATES).get(old_state))
            if allowed_from is None and new_state not in valid_from:
                raise ValidationError(_('Invalid workflow transition from %s to %s.') % (
                    dict(REQUEST_STATES).get(old_state), dict(REQUEST_STATES).get(new_state)))
            rec.with_context(smartserve_allow_state_write=True).write({'state': new_state})
            rec._log_activity(event_type, description, old_state=old_state, new_state=new_state, notes=notes)
        return True

    def action_send_document_request(self):
        storage = get_storage_provider(self.env)
        communication = get_communication_provider(self.env)
        for rec in self:
            if rec.state not in ('draft', 'expired', 'cancelled'):
                raise ValidationError(_('A document request can only be sent from Draft, Expired, or Cancelled.'))
            rec._apply_template_documents()
            if not rec.required_document_ids:
                raise UserError(_('Add at least one document requirement before sending the request.'))
            if not rec.sharepoint_folder_id:
                rec.sharepoint_folder_id = storage.create_request_folder(rec)
            old_state = rec.state
            rec.with_context(smartserve_allow_state_write=True).write({
                'state': 'waiting_documents', 'upload_revoked': False,
                'upload_expires_at': rec._default_expiry(),
            })
            rec._log_activity('secure_link_generated', _('Secure upload link generated.'), old_state=old_state, new_state='waiting_documents')
            communication.send_initial_request(rec)
            rec._log_activity('request_sent', _('Document request sent to customer.'))
        return True

    def action_generate_request(self):
        return self.action_send_document_request()

    def action_start_review(self):
        return self._set_workflow_state('document_review', _('Document review started.'), 'review_started',
                                        allowed_from=('documents_received',))

    def action_request_more_documents(self):
        for rec in self:
            old = rec.state
            if old not in ('documents_received', 'document_review', 'action_required', 'documents_partially_received'):
                raise ValidationError(_('More documents cannot be requested from the current stage.'))
            rec.with_context(smartserve_allow_state_write=True).write({
                'state': 'additional_documents_required', 'upload_revoked': False,
                'upload_expires_at': rec._default_expiry(),
            })
            rec._log_activity('additional_documents_requested', _('Additional documents requested.'), old_state=old,
                              new_state='additional_documents_required')
        return True

    def action_mark_documents_approved(self):
        for rec in self:
            blocking = rec.required_document_ids.filtered(lambda d: d.required and d.review_status != 'approved')
            if blocking:
                raise ValidationError(_('All mandatory documents must be approved first: %s') % ', '.join(blocking.mapped('name')))
        return self._set_workflow_state('ready_for_processing', _('All mandatory documents approved.'),
                                        'mandatory_documents_approved', allowed_from=('document_review', 'documents_received'))

    def action_mark_ready_for_processing(self):
        return self.action_mark_documents_approved()

    def action_submit_to_authority(self):
        for rec in self:
            if not rec.authority_id:
                raise ValidationError(_('Select an authority before submission.'))
            rec.write({'submission_date': rec.submission_date or fields.Date.context_today(rec), 'external_status': 'submitted'})
        return self._set_workflow_state('submitted_to_authority', _('Request submitted to authority.'),
                                        'submitted_to_authority', allowed_from=('ready_for_processing', 'action_required'))

    def action_mark_external_processing(self):
        self.write({'external_status': 'in_process'})
        return self._set_workflow_state('external_processing', _('External processing started.'),
                                        'external_processing_started', allowed_from=('submitted_to_authority', 'ready_for_processing', 'action_required'))

    def action_mark_action_required(self):
        self.write({'external_status': 'action_required'})
        return self._set_workflow_state('action_required', _('Action is required before processing can continue.'),
                                        'authority_action_required', allowed_from=('ready_for_processing', 'submitted_to_authority', 'external_processing', 'service_approved'))

    def action_mark_service_approved(self):
        self.write({'external_status': 'approved', 'approval_date': fields.Date.context_today(self)})
        return self._set_workflow_state('service_approved', _('Service approved.'), 'service_approved',
                                        allowed_from=('ready_for_processing', 'submitted_to_authority', 'external_processing', 'action_required'))

    def action_mark_result_received(self):
        for rec in self:
            if rec.state != 'service_approved':
                raise ValidationError(_('The result can only be received after service approval.'))
            rec.result_received_date = fields.Date.context_today(rec)
            rec._log_activity('result_received', _('Final result received.'))
        return True

    def action_deliver_to_customer(self):
        for rec in self:
            if rec.state != 'service_approved' or not rec.result_received_date:
                raise ValidationError(_('Receive the final result before delivering it to the customer.'))
            rec.write({'delivered_to_customer': True, 'final_delivery_date': fields.Date.context_today(rec)})
            rec._log_activity('result_delivered', _('Final result delivered to customer.'))
        return True

    def action_mark_completed(self):
        if (self.env.user.smartserve_role and not self.env.user.smartserve_can_complete_requests and
                not self.env.user.has_group('smartserve_document_collection.group_smartserve_admin') and
                not self.env.is_superuser()):
            raise AccessError(_('You do not have permission to complete requests.'))
        for rec in self:
            override = rec.completion_override
            if override:
                if not self.env.user.has_group('smartserve_document_collection.group_smartserve_manager'):
                    raise AccessError(_('Only a SmartServe manager may override completion checks.'))
                if not rec.completion_override_reason:
                    raise ValidationError(_('Enter a manager override reason.'))
            if not rec.completion_notes:
                raise ValidationError(_('Completion notes are required.'))
            if not override:
                checks = [
                    (not rec.required_document_ids.filtered(lambda d: d.required and d.review_status != 'approved'), _('All mandatory documents must be approved.')),
                    (rec.state == 'service_approved', _('The service must be approved before completion.')),
                    (bool(rec.result_received_date), _('The final result must be received.')),
                    (rec.delivered_to_customer and bool(rec.final_delivery_date), _('The final result must be delivered to the customer.')),
                ]
                failures = [message for passed, message in checks if not passed]
                if failures:
                    raise ValidationError('\n'.join(failures))
            old = rec.state
            rec.with_context(smartserve_allow_state_write=True).write({
                'state': 'completed', 'upload_revoked': True, 'completed_date': fields.Datetime.now()
            })
            rec._log_activity('request_completed', _('Request completed.'), old_state=old,
                              new_state='completed', notes=rec.completion_override_reason if override else None)
        return True

    def action_put_on_hold(self):
        for rec in self:
            if rec.state in TERMINAL_STATES or rec.state == 'on_hold':
                raise ValidationError(_('This request cannot be put on hold.'))
            old = rec.state
            rec.with_context(smartserve_allow_state_write=True).write({'state_before_hold': old, 'state': 'on_hold'})
            rec._log_activity('request_on_hold', _('Request put on hold.'), old_state=old, new_state='on_hold')
        return True

    def action_cancel_request(self):
        for rec in self:
            if rec.state in ('completed', 'cancelled'):
                raise ValidationError(_('This request cannot be cancelled.'))
            old = rec.state
            rec.with_context(smartserve_allow_state_write=True).write({'state': 'cancelled', 'upload_revoked': True})
            rec._log_activity('request_cancelled', _('Request cancelled.'), old_state=old, new_state='cancelled')
        return True

    def action_mark_rejected(self):
        return self._set_workflow_state('rejected', _('Request rejected.'), 'request_rejected',
                                        allowed_from=('document_review', 'submitted_to_authority', 'external_processing'))

    def action_reopen_request(self):
        for rec in self:
            if rec.state not in ('on_hold', 'cancelled', 'rejected', 'expired', 'completed'):
                raise ValidationError(_('Only closed, expired, or on-hold requests can be reopened.'))
            old = rec.state
            if old == 'on_hold' and rec.state_before_hold:
                target = rec.state_before_hold
            elif rec.required_document_ids and all(d.review_status == 'approved' for d in rec.required_document_ids.filtered('required')):
                target = 'ready_for_processing'
            elif rec.upload_status == 'uploaded':
                target = 'document_review'
            elif rec.upload_status == 'partially_uploaded':
                target = 'documents_partially_received'
            else:
                target = 'waiting_documents'
            rec.with_context(smartserve_allow_state_write=True).write({
                'state': target, 'upload_revoked': False, 'completed_date': False,
                'upload_expires_at': rec._default_expiry(),
            })
            rec._log_activity('request_reopened', _('Request reopened.'), old_state=old, new_state=target)
        return True

    def action_revoke_upload_link(self):
        self.write({'upload_revoked': True})
        for rec in self:
            rec._log_activity('link_revoked', _('Secure upload link revoked.'))
        return True

    def _recalculate_upload_workflow(self):
        for rec in self:
            mandatory = rec.required_document_ids.filtered('required')
            uploaded_count = len(mandatory.filtered(lambda d: d.upload_status == 'uploaded'))
            if not mandatory or uploaded_count == len(mandatory):
                target = 'documents_received'
                event = 'all_documents_received'
                summary = _('All mandatory documents received.')
            elif uploaded_count:
                target = 'documents_partially_received'
                event = 'documents_partially_received'
                summary = _('Some mandatory documents received; more are required.')
            else:
                target = 'waiting_documents'
                event = 'waiting_documents'
                summary = _('Waiting for customer documents.')
            if rec.state in ('waiting_documents', 'documents_partially_received', 'documents_received',
                             'document_review', 'additional_documents_required') and rec.state != target:
                old = rec.state
                rec.with_context(smartserve_allow_state_write=True).state = target
                rec._log_activity(event, summary, old_state=old, new_state=target)
                if target == 'documents_received':
                    rec._schedule_review_activity()

    def _recalculate_review_workflow(self):
        for rec in self:
            mandatory = rec.required_document_ids.filtered('required')
            if mandatory.filtered(lambda d: d.review_status in ('rejected', 'replacement_required')):
                target = 'additional_documents_required'
            elif mandatory and all(d.review_status == 'approved' for d in mandatory):
                target = 'ready_for_processing'
            else:
                return
            if rec.state != target:
                old = rec.state
                rec.with_context(smartserve_allow_state_write=True).state = target
                event = 'mandatory_documents_approved' if target == 'ready_for_processing' else 'additional_documents_requested'
                summary = _('All mandatory documents approved.') if target == 'ready_for_processing' else _('Additional or replacement documents required.')
                rec._log_activity(event, summary, old_state=old, new_state=target)

    def _schedule_review_activity(self):
        self.ensure_one()
        if not self.assigned_user_id:
            return
        existing = self.timeline_event_ids.filtered(lambda event: event.activity_type == 'review_activity_created')
        if not existing:
            self.activity_schedule('mail.mail_activity_data_todo', user_id=self.assigned_user_id.id,
                                   summary=_('Review uploaded documents'))
            self._log_activity('review_activity_created', _('Review activity assigned to %s.') % self.assigned_user_id.name)

    def _log_activity(self, activity_type, summary, old_state=None, new_state=None, related_document=None, notes=None):
        now = fields.Datetime.now()
        for rec in self:
            self.env['smartserve.request.activity'].sudo().create({
                'request_id': rec.id, 'actor_id': self.env.user.id, 'event_date': now,
                'activity_type': activity_type, 'summary': summary,
                'old_state': old_state, 'new_state': new_state,
                'related_document_id': related_document.id if related_document else False,
                'notes': notes,
            })
            rec.with_context(smartserve_allow_state_write=True).write({'last_activity_date': now})


class SmartServeRequiredDocument(models.Model):
    _name = 'smartserve.required.document'
    _description = 'SmartServe Required Document'
    _order = 'sequence, id'

    sequence = fields.Integer(default=10)
    request_id = fields.Many2one('smartserve.document.request', required=True, ondelete='cascade')
    name = fields.Char(required=True)
    description = fields.Text()
    required = fields.Boolean(default=True)
    allowed_extensions = fields.Char(default='pdf,jpg,jpeg,png')
    max_size_mb = fields.Integer(default=10)
    upload_status = fields.Selection(UPLOAD_STATES, default='not_uploaded', required=True)
    review_status = fields.Selection(REVIEW_STATES, default='pending', required=True)
    sharepoint_file_id = fields.Char(readonly=True)
    sharepoint_file_url = fields.Char(readonly=True)
    uploaded_at = fields.Datetime(readonly=True)
    rejection_reason = fields.Text()
    internal_review_notes = fields.Text()
    reviewer_id = fields.Many2one('res.users', readonly=True)
    review_date = fields.Datetime(readonly=True)
    uploaded_document_ids = fields.One2many('smartserve.uploaded.document', 'required_document_id', string='Uploaded Files')

    def _set_review(self, status, event, label):
        for doc in self:
            if status in ('rejected', 'replacement_required') and not doc.rejection_reason:
                raise ValidationError(_('Enter a rejection/replacement reason for %s.') % doc.name)
            values = {
                'review_status': status, 'reviewer_id': self.env.user.id,
                'review_date': fields.Datetime.now(),
            }
            if status == 'replacement_required':
                values['upload_status'] = 'not_uploaded'
            doc.write(values)
            latest = doc.uploaded_document_ids[:1]
            if latest:
                latest.write({'review_status': status, 'rejection_reason': doc.rejection_reason,
                              'reviewer_id': self.env.user.id, 'review_date': fields.Datetime.now()})
            doc.request_id._log_activity(event, _('%s: %s') % (label, doc.name),
                                         related_document=doc, notes=doc.rejection_reason or doc.internal_review_notes)
            doc.request_id._recalculate_review_workflow()
        return True

    def action_approve(self):
        return self._set_review('approved', 'document_approved', _('Document approved'))

    def action_reject(self):
        return self._set_review('rejected', 'document_rejected', _('Document rejected'))

    def action_request_replacement(self):
        return self._set_review('replacement_required', 'replacement_requested', _('Replacement requested'))


class SmartServeUploadedDocument(models.Model):
    _name = 'smartserve.uploaded.document'
    _description = 'SmartServe Uploaded Document Metadata'
    _order = 'uploaded_at desc, id desc'

    name = fields.Char(required=True)
    request_id = fields.Many2one('smartserve.document.request', required=True, ondelete='cascade')
    required_document_id = fields.Many2one('smartserve.required.document', ondelete='set null')
    storage_provider = fields.Char(required=True, default='sharepoint')
    storage_file_id = fields.Char(required=True)
    storage_url = fields.Char()
    filename = fields.Char(required=True)
    mimetype = fields.Char()
    file_size = fields.Integer()
    uploaded_at = fields.Datetime(default=fields.Datetime.now, required=True)
    review_status = fields.Selection(REVIEW_STATES, default='pending', required=True)
    rejection_reason = fields.Text()
    staff_notes = fields.Text()
    reviewer_id = fields.Many2one('res.users', readonly=True)
    review_date = fields.Datetime(readonly=True)

    def action_approve(self):
        for doc in self:
            if doc.required_document_id:
                doc.required_document_id.action_approve()
            else:
                doc.write({'review_status': 'approved', 'reviewer_id': self.env.user.id, 'review_date': fields.Datetime.now()})
                doc.request_id._log_activity('document_approved', _('Document approved: %s') % doc.name)
        return True

    def action_reject(self):
        for doc in self:
            if not doc.rejection_reason:
                raise ValidationError(_('Enter a rejection reason.'))
            if doc.required_document_id:
                doc.required_document_id.write({'rejection_reason': doc.rejection_reason})
                doc.required_document_id.action_reject()
            else:
                doc.write({'review_status': 'rejected', 'reviewer_id': self.env.user.id, 'review_date': fields.Datetime.now()})
                doc.request_id._log_activity('document_rejected', _('Document rejected: %s') % doc.name, notes=doc.rejection_reason)
        return True

    def action_request_replacement(self):
        for doc in self:
            if not doc.rejection_reason:
                raise ValidationError(_('Enter a replacement reason.'))
            if doc.required_document_id:
                doc.required_document_id.write({'rejection_reason': doc.rejection_reason})
                doc.required_document_id.action_request_replacement()
        return True


class SmartServeRequestActivity(models.Model):
    _name = 'smartserve.request.activity'
    _description = 'SmartServe Request Timeline Event'
    _order = 'event_date desc, id desc'

    request_id = fields.Many2one('smartserve.document.request', required=True, ondelete='cascade', index=True)
    actor_id = fields.Many2one('res.users', default=lambda self: self.env.user, readonly=True)
    event_date = fields.Datetime(default=fields.Datetime.now, required=True, readonly=True)
    activity_type = fields.Char(required=True, readonly=True)
    summary = fields.Char(required=True, readonly=True)
    old_state = fields.Selection(REQUEST_STATES, readonly=True)
    new_state = fields.Selection(REQUEST_STATES, readonly=True)
    related_document_id = fields.Many2one('smartserve.required.document', readonly=True, ondelete='set null')
    notes = fields.Text(readonly=True)

    def write(self, vals):
        if not self.env.user.has_group('smartserve_document_collection.group_smartserve_manager'):
            raise AccessError(_('Timeline events are read-only.'))
        return super().write(vals)

    def unlink(self):
        if not self.env.user.has_group('smartserve_document_collection.group_smartserve_manager'):
            raise AccessError(_('Timeline events cannot be deleted by staff.'))
        return super().unlink()


class SmartServeUploadAttempt(models.Model):
    _name = 'smartserve.upload.attempt'
    _description = 'SmartServe Public Upload Attempt'
    _order = 'create_date desc'

    request_id = fields.Many2one('smartserve.document.request', ondelete='cascade')
    token_hash = fields.Char(required=True, index=True)
    ip_address = fields.Char(index=True)
    user_agent = fields.Char()
    result = fields.Selection([
        ('view', 'Page View'), ('success', 'Successful Upload'),
        ('validation_error', 'Validation Error'), ('blocked', 'Rate Limited'), ('invalid', 'Invalid Link'),
    ], required=True)
    summary = fields.Char()


class SmartServeWorkflowDashboard(models.Model):
    _name = 'smartserve.workflow.dashboard'
    _description = 'SmartServe Operational Dashboard'

    name = fields.Char(default='Operations Dashboard', required=True)
    user_name = fields.Char(compute='_compute_user_context')
    greeting = fields.Char(compute='_compute_user_context')
    waiting_documents_count = fields.Integer(compute='_compute_counts')
    partially_received_count = fields.Integer(compute='_compute_counts')
    documents_received_count = fields.Integer(compute='_compute_counts')
    pending_review_count = fields.Integer(compute='_compute_counts')
    additional_documents_count = fields.Integer(compute='_compute_counts')
    ready_processing_count = fields.Integer(compute='_compute_counts')
    submitted_count = fields.Integer(compute='_compute_counts')
    external_processing_count = fields.Integer(compute='_compute_counts')
    action_required_count = fields.Integer(compute='_compute_counts')
    overdue_count = fields.Integer(compute='_compute_counts')
    completed_month_count = fields.Integer(compute='_compute_counts')
    service_approved_count = fields.Integer(compute='_compute_counts')
    unassigned_count = fields.Integer(compute='_compute_counts')
    expired_links_count = fields.Integer(compute='_compute_counts')
    stored_files_count = fields.Integer(compute='_compute_counts')
    communication_count = fields.Integer(compute='_compute_counts')
    active_users_count = fields.Integer(compute='_compute_counts')
    managers_count = fields.Integer(compute='_compute_counts')
    employees_count = fields.Integer(compute='_compute_counts')
    inactive_users_count = fields.Integer(compute='_compute_counts')
    my_pending_review_count = fields.Integer(compute='_compute_counts')
    my_action_required_count = fields.Integer(compute='_compute_counts')
    my_overdue_count = fields.Integer(compute='_compute_counts')
    my_ready_count = fields.Integer(compute='_compute_counts')
    my_awaiting_customer_count = fields.Integer(compute='_compute_counts')
    my_awaiting_authority_count = fields.Integer(compute='_compute_counts')
    my_active_count = fields.Integer(compute='_compute_counts')
    my_completed_month_count = fields.Integer(compute='_compute_counts')
    action_request_ids = fields.Many2many('smartserve.document.request', compute='_compute_action_requests')
    team_request_ids = fields.Many2many('smartserve.document.request', compute='_compute_dashboard_lists', relation='smartserve_dashboard_team_rel')
    overdue_request_ids = fields.Many2many('smartserve.document.request', compute='_compute_dashboard_lists', relation='smartserve_dashboard_overdue_rel')
    attention_request_ids = fields.Many2many('smartserve.document.request', compute='_compute_dashboard_lists', relation='smartserve_dashboard_attention_rel')
    upcoming_request_ids = fields.Many2many('smartserve.document.request', compute='_compute_dashboard_lists', relation='smartserve_dashboard_upcoming_rel')
    recent_upload_ids = fields.Many2many('smartserve.uploaded.document', compute='_compute_dashboard_lists', relation='smartserve_dashboard_upload_rel')
    recent_event_ids = fields.Many2many('smartserve.request.activity', compute='_compute_dashboard_lists', relation='smartserve_dashboard_event_rel')

    def _compute_user_context(self):
        hour = fields.Datetime.context_timestamp(self, fields.Datetime.now()).hour
        greeting = _('Good morning') if hour < 12 else (_('Good afternoon') if hour < 18 else _('Good evening'))
        for dashboard in self:
            dashboard.user_name = self.env.user.name
            dashboard.greeting = greeting

    def _domain(self, key):
        today = fields.Date.context_today(self)
        month_start = today.replace(day=1)
        return {
            'waiting': [('state', '=', 'waiting_documents')],
            'partial': [('state', '=', 'documents_partially_received')],
            'received': [('state', '=', 'documents_received')],
            'review': [('state', '=', 'document_review')],
            'additional': [('state', '=', 'additional_documents_required')],
            'ready': [('state', '=', 'ready_for_processing')],
            'submitted': [('state', '=', 'submitted_to_authority')],
            'external': [('state', '=', 'external_processing')],
            'action': [('state', '=', 'action_required')],
            'overdue': [('due_date', '<', today), ('state', 'not in', TERMINAL_STATES)],
            'completed_month': [('completed_date', '>=', fields.Datetime.to_datetime(month_start))],
        }[key]

    def _compute_counts(self):
        model = self.env['smartserve.document.request']
        for dashboard in self:
            dashboard.waiting_documents_count = model.search_count(dashboard._domain('waiting'))
            dashboard.partially_received_count = model.search_count(dashboard._domain('partial'))
            dashboard.documents_received_count = model.search_count(dashboard._domain('received'))
            dashboard.pending_review_count = model.search_count(dashboard._domain('review'))
            dashboard.additional_documents_count = model.search_count(dashboard._domain('additional'))
            dashboard.ready_processing_count = model.search_count(dashboard._domain('ready'))
            dashboard.submitted_count = model.search_count(dashboard._domain('submitted'))
            dashboard.external_processing_count = model.search_count(dashboard._domain('external'))
            dashboard.action_required_count = model.search_count(dashboard._domain('action'))
            dashboard.overdue_count = model.search_count(dashboard._domain('overdue'))
            dashboard.completed_month_count = model.search_count(dashboard._domain('completed_month'))
            dashboard.service_approved_count = model.search_count([('state', '=', 'service_approved')])
            dashboard.unassigned_count = model.search_count([('assigned_user_id', '=', False), ('state', 'not in', TERMINAL_STATES)])
            dashboard.expired_links_count = model.search_count([
                ('upload_expires_at', '<', fields.Datetime.now()), ('upload_revoked', '=', False),
                ('state', 'not in', TERMINAL_STATES),
            ])
            dashboard.stored_files_count = self.env['smartserve.uploaded.document'].search_count([])
            dashboard.communication_count = model.search_count([('communication_status', 'in', ('queued', 'sent'))])
            users = self.env['res.users'].sudo().with_context(active_test=False)
            dashboard.active_users_count = users.search_count([
                ('smartserve_role', '!=', False), ('active', '=', True)
            ])
            dashboard.managers_count = users.search_count([
                ('smartserve_role', '=', 'manager'), ('active', '=', True)
            ])
            dashboard.employees_count = users.search_count([
                ('smartserve_role', '=', 'employee'), ('active', '=', True)
            ])
            dashboard.inactive_users_count = users.search_count([
                ('smartserve_role', '!=', False), ('active', '=', False)
            ])

            mine = [('assigned_user_id', '=', self.env.user.id)]
            dashboard.my_pending_review_count = model.search_count(mine + [('state', 'in', ('documents_received', 'document_review'))])
            dashboard.my_action_required_count = model.search_count(mine + [('state', '=', 'action_required')])
            dashboard.my_overdue_count = model.search_count(mine + dashboard._domain('overdue'))
            dashboard.my_ready_count = model.search_count(mine + [('state', '=', 'ready_for_processing')])
            dashboard.my_awaiting_customer_count = model.search_count(mine + [
                ('state', 'in', ('waiting_documents', 'documents_partially_received', 'additional_documents_required'))
            ])
            dashboard.my_awaiting_authority_count = model.search_count(mine + [
                ('state', 'in', ('submitted_to_authority', 'external_processing'))
            ])
            dashboard.my_active_count = model.search_count(mine + [('state', 'not in', TERMINAL_STATES)])
            dashboard.my_completed_month_count = model.search_count(mine + dashboard._domain('completed_month'))

    def _compute_action_requests(self):
        active_states = tuple(state for state, _label in REQUEST_STATES if state not in TERMINAL_STATES)
        for dashboard in self:
            dashboard.action_request_ids = self.env['smartserve.document.request'].search([
                ('state', 'in', active_states), ('assigned_user_id', '=', self.env.user.id),
            ], limit=50, order='priority desc, due_date, last_activity_date')

    def _compute_dashboard_lists(self):
        request_model = self.env['smartserve.document.request']
        today = fields.Date.context_today(self)
        mine = [('assigned_user_id', '=', self.env.user.id)]
        active = [('state', 'not in', TERMINAL_STATES)]
        for dashboard in self:
            dashboard.team_request_ids = request_model.search(active, limit=8, order='priority desc, due_date, last_activity_date')
            dashboard.overdue_request_ids = request_model.search(
                [('due_date', '<', today)] + active, limit=8, order='due_date, priority desc')
            dashboard.attention_request_ids = request_model.search([
                '|', ('state', 'in', ('action_required', 'additional_documents_required')),
                '&', ('priority', 'in', ('2', '3')), ('state', 'not in', TERMINAL_STATES),
            ], limit=8, order='priority desc, last_activity_date')
            dashboard.upcoming_request_ids = request_model.search(
                mine + active + [('due_date', '>=', today)], limit=8, order='due_date, priority desc')
            dashboard.recent_upload_ids = self.env['smartserve.uploaded.document'].search([
                ('request_id.assigned_user_id', '=', self.env.user.id),
            ], limit=8, order='uploaded_at desc')
            dashboard.recent_event_ids = self.env['smartserve.request.activity'].search([], limit=8, order='event_date desc')

    def _open(self, key):
        self.ensure_one()
        action = self.env['ir.actions.actions']._for_xml_id('smartserve_document_collection.action_smartserve_document_requests')
        action['domain'] = self._domain(key)
        return action

    def action_open_waiting(self): return self._open('waiting')
    def action_open_partial(self): return self._open('partial')
    def action_open_received(self): return self._open('received')
    def action_open_review(self): return self._open('review')
    def action_open_additional(self): return self._open('additional')
    def action_open_ready(self): return self._open('ready')
    def action_open_submitted(self): return self._open('submitted')
    def action_open_external(self): return self._open('external')
    def action_open_action_required(self): return self._open('action')
    def action_open_overdue(self): return self._open('overdue')
    def action_open_completed_month(self): return self._open('completed_month')

    def action_open_service_approved(self):
        action = self.env['ir.actions.actions']._for_xml_id('smartserve_document_collection.action_smartserve_document_requests')
        action['domain'] = [('state', '=', 'service_approved')]
        return action

    def action_open_unassigned(self):
        action = self.env['ir.actions.actions']._for_xml_id('smartserve_document_collection.action_smartserve_document_requests')
        action['domain'] = [('assigned_user_id', '=', False), ('state', 'not in', TERMINAL_STATES)]
        return action

    def action_open_expired_links(self):
        action = self.env['ir.actions.actions']._for_xml_id('smartserve_document_collection.action_smartserve_document_requests')
        action['domain'] = [
            ('upload_expires_at', '<', fields.Datetime.now()), ('upload_revoked', '=', False),
            ('state', 'not in', TERMINAL_STATES),
        ]
        return action

    def action_open_uploaded_documents(self):
        return self.env['ir.actions.actions']._for_xml_id('smartserve_document_collection.action_smartserve_uploaded_documents')

    def action_open_communications(self):
        action = self.env['ir.actions.actions']._for_xml_id('smartserve_document_collection.action_smartserve_document_requests')
        action['domain'] = [('communication_status', 'in', ('queued', 'sent'))]
        return action

    def action_new_request(self):
        return {
            'type': 'ir.actions.act_window', 'name': _('New Request'),
            'res_model': 'smartserve.document.request', 'view_mode': 'form', 'target': 'current',
        }

    def action_manage_users(self):
        return self.env['ir.actions.actions']._for_xml_id(
            'smartserve_document_collection.action_smartserve_users'
        )

    def _open_my(self, domain):
        action = self.env['ir.actions.actions']._for_xml_id('smartserve_document_collection.action_smartserve_document_requests')
        action['domain'] = [('assigned_user_id', '=', self.env.user.id)] + domain
        return action

    def action_open_my_review(self): return self._open_my([('state', 'in', ('documents_received', 'document_review'))])
    def action_open_my_action(self): return self._open_my([('state', '=', 'action_required')])
    def action_open_my_overdue(self): return self._open_my(self._domain('overdue'))
    def action_open_my_ready(self): return self._open_my([('state', '=', 'ready_for_processing')])
    def action_open_my_customer(self): return self._open_my([('state', 'in', ('waiting_documents', 'documents_partially_received', 'additional_documents_required'))])
    def action_open_my_authority(self): return self._open_my([('state', 'in', ('submitted_to_authority', 'external_processing'))])
    def action_open_my_active(self): return self._open_my([('state', 'not in', TERMINAL_STATES)])
    def action_open_my_completed(self): return self._open_my(self._domain('completed_month'))
