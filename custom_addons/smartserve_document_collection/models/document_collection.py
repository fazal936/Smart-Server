import secrets
from datetime import timedelta

from odoo import api, fields, models, _
from odoo.exceptions import UserError

from ..services.communication import get_communication_provider
from ..services.storage import get_storage_provider


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
    request_ids = fields.One2many('smartserve.document.request', 'customer_id')
    request_count = fields.Integer(compute='_compute_request_count')

    @api.depends('request_ids')
    def _compute_request_count(self):
        for customer in self:
            customer.request_count = len(customer.request_ids)


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
    document_template_ids = fields.One2many(
        'smartserve.service.template.document',
        'template_id',
        string='Required Documents',
    )


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


class SmartServeDocumentRequest(models.Model):
    _name = 'smartserve.document.request'
    _description = 'SmartServe Document Request'
    _order = 'create_date desc'

    name = fields.Char(default='New', readonly=True, copy=False)
    title = fields.Char(required=True)
    customer_id = fields.Many2one('smartserve.customer', required=True, ondelete='restrict')
    customer_name = fields.Char(related='customer_id.name', store=True)
    email = fields.Char(related='customer_id.email', store=True)
    mobile = fields.Char(related='customer_id.mobile', store=True)
    template_id = fields.Many2one('smartserve.service.template', string='Service Template', ondelete='restrict')
    service_name = fields.Char(required=True)
    assigned_user_id = fields.Many2one('res.users', default=lambda self: self.env.user)
    due_date = fields.Date()
    internal_notes = fields.Text()
    state = fields.Selection([
        ('draft', 'Draft'),
        ('waiting', 'Waiting for Customer'),
        ('submitted', 'Submitted'),
        ('review', 'Pending Review'),
        ('completed', 'Completed'),
        ('rejected', 'Rejected'),
        ('revoked', 'Revoked'),
    ], default='draft', required=True)
    upload_token = fields.Char(default=lambda self: self._generate_token(), readonly=True, copy=False, index=True)
    upload_expires_at = fields.Datetime(default=lambda self: self._default_expiry(), required=True)
    upload_revoked = fields.Boolean(default=False)
    allow_multiple_uploads = fields.Boolean(default=True)
    sharepoint_folder_id = fields.Char(readonly=True)
    sharepoint_folder_url = fields.Char(readonly=True)
    communication_status = fields.Selection([
        ('not_sent', 'Not Sent'),
        ('not_configured', 'Provider Not Configured'),
        ('queued', 'Queued'),
        ('sent', 'Sent'),
        ('failed', 'Failed'),
    ], default='not_sent', required=True)
    required_document_ids = fields.One2many('smartserve.required.document', 'request_id', string='Required Documents')
    uploaded_document_ids = fields.One2many('smartserve.uploaded.document', 'request_id', string='Uploaded Documents')
    activity_ids = fields.One2many('smartserve.request.activity', 'request_id', string='Activity Timeline')
    upload_url = fields.Char(compute='_compute_upload_url')
    required_count = fields.Integer(compute='_compute_counts')
    uploaded_count = fields.Integer(compute='_compute_counts')
    pending_count = fields.Integer(compute='_compute_counts')
    rejected_count = fields.Integer(compute='_compute_counts')

    _sql_constraints = [
        ('upload_token_unique', 'unique(upload_token)', 'Upload token must be unique.'),
    ]

    @api.model_create_multi
    def create(self, vals_list):
        sequence = self.env['ir.sequence']
        for vals in vals_list:
            if vals.get('name', 'New') == 'New':
                vals['name'] = sequence.next_by_code('smartserve.document.request') or 'New'
        records = super().create(vals_list)
        for record in records:
            record._apply_template_documents()
            record._log_activity('request_created', _('Request created.'))
        return records

    @api.onchange('template_id')
    def _onchange_template_id(self):
        if self.template_id:
            self.service_name = self.template_id.name
            self.title = self.title or self.template_id.name
            self.due_date = fields.Date.today() + timedelta(days=self.template_id.due_days or 0)
            self.required_document_ids = [(5, 0, 0)] + [
                (0, 0, {
                    'sequence': template_doc.sequence,
                    'name': template_doc.name,
                    'description': template_doc.description,
                    'required': template_doc.required,
                    'allowed_extensions': template_doc.allowed_extensions,
                    'max_size_mb': template_doc.max_size_mb,
                })
                for template_doc in self.template_id.document_template_ids
            ]

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
        for request_record in self:
            request_record.upload_url = f"{base_url}/upload/{request_record.upload_token}" if request_record.upload_token else False

    @api.depends('required_document_ids.upload_status', 'uploaded_document_ids.review_status')
    def _compute_counts(self):
        for request_record in self:
            request_record.required_count = len(request_record.required_document_ids)
            request_record.uploaded_count = len(request_record.uploaded_document_ids)
            request_record.pending_count = len(request_record.required_document_ids.filtered(
                lambda doc: doc.upload_status != 'uploaded' or doc.review_status in ('pending', 'rejected')
            ))
            request_record.rejected_count = len(request_record.uploaded_document_ids.filtered(
                lambda doc: doc.review_status == 'rejected'
            ))

    def _apply_template_documents(self):
        for request_record in self:
            if request_record.required_document_ids or not request_record.template_id:
                continue
            request_record.required_document_ids = [
                (0, 0, {
                    'sequence': template_doc.sequence,
                    'name': template_doc.name,
                    'description': template_doc.description,
                    'required': template_doc.required,
                    'allowed_extensions': template_doc.allowed_extensions,
                    'max_size_mb': template_doc.max_size_mb,
                })
                for template_doc in request_record.template_id.document_template_ids
            ]

    def is_upload_link_usable(self):
        self.ensure_one()
        return (
            self.state in ('waiting', 'submitted', 'review')
            and not self.upload_revoked
            and self.upload_expires_at >= fields.Datetime.now()
            and (self.allow_multiple_uploads or self.state == 'waiting')
        )

    def action_generate_request(self):
        storage_provider = get_storage_provider(self.env)
        communication_provider = get_communication_provider(self.env)
        for request_record in self:
            if not request_record.required_document_ids:
                request_record._apply_template_documents()
            if not request_record.required_document_ids:
                raise UserError(_(
                    'Add at least one required document before generating the request. '
                    'You can add it in the Required Documents tab, or select a Service Template that contains documents.'
                ))
            if not request_record.sharepoint_folder_id:
                request_record.sharepoint_folder_id = storage_provider.create_request_folder(request_record)
            request_record.write({
                'state': 'waiting',
                'upload_revoked': False,
                'upload_expires_at': self._default_expiry(),
            })
            request_record._log_activity('link_generated', _('Secure upload link generated.'))
            communication_provider.send_initial_request(request_record)
        return True

    def action_start_review(self):
        self.write({'state': 'review'})
        for request_record in self:
            request_record._log_activity('review_started', _('Staff started review.'))

    def action_mark_completed(self):
        self.write({'state': 'completed', 'upload_revoked': True})
        for request_record in self:
            request_record._log_activity('request_completed', _('Request completed and upload link revoked.'))

    def action_mark_rejected(self):
        self.write({'state': 'rejected'})
        for request_record in self:
            request_record._log_activity('request_rejected', _('Request rejected.'))

    def action_request_more_documents(self):
        for request_record in self:
            request_record.write({
                'state': 'waiting',
                'upload_revoked': False,
                'upload_expires_at': self._default_expiry(),
            })
            request_record._log_activity('more_documents_requested', _('More documents requested using the same secure link.'))

    def action_revoke_upload_link(self):
        self.write({'upload_revoked': True, 'state': 'revoked'})
        for request_record in self:
            request_record._log_activity('link_revoked', _('Secure upload link revoked.'))

    def _log_activity(self, activity_type, summary):
        for request_record in self:
            self.env['smartserve.request.activity'].create({
                'request_id': request_record.id,
                'activity_type': activity_type,
                'summary': summary,
            })


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
    upload_status = fields.Selection([('missing', 'Missing'), ('uploaded', 'Uploaded')], default='missing', required=True)
    review_status = fields.Selection([
        ('pending', 'Pending Review'),
        ('approved', 'Approved'),
        ('rejected', 'Rejected'),
    ], default='pending', required=True)
    sharepoint_file_id = fields.Char(readonly=True)
    sharepoint_file_url = fields.Char(readonly=True)
    uploaded_document_ids = fields.One2many('smartserve.uploaded.document', 'required_document_id', string='Uploaded Files')


class SmartServeUploadedDocument(models.Model):
    _name = 'smartserve.uploaded.document'
    _description = 'SmartServe Uploaded Document Metadata'
    _order = 'create_date desc'

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
    review_status = fields.Selection([
        ('pending', 'Pending Review'),
        ('approved', 'Approved'),
        ('rejected', 'Rejected'),
    ], default='pending', required=True)
    staff_notes = fields.Text()

    def action_approve(self):
        self.write({'review_status': 'approved'})
        for document in self:
            if document.required_document_id:
                document.required_document_id.review_status = 'approved'
            document.request_id._log_activity('review_approved', _('Document approved: %s') % document.name)

    def action_reject(self):
        self.write({'review_status': 'rejected'})
        for document in self:
            if document.required_document_id:
                document.required_document_id.review_status = 'rejected'
            document.request_id._log_activity('review_rejected', _('Document rejected: %s') % document.name)


class SmartServeRequestActivity(models.Model):
    _name = 'smartserve.request.activity'
    _description = 'SmartServe Request Activity'
    _order = 'create_date desc'

    request_id = fields.Many2one('smartserve.document.request', required=True, ondelete='cascade')
    activity_type = fields.Char(required=True)
    summary = fields.Char(required=True)


class SmartServeUploadAttempt(models.Model):
    _name = 'smartserve.upload.attempt'
    _description = 'SmartServe Public Upload Attempt'
    _order = 'create_date desc'

    request_id = fields.Many2one('smartserve.document.request', ondelete='cascade')
    token_hash = fields.Char(required=True, index=True)
    ip_address = fields.Char(index=True)
    user_agent = fields.Char()
    result = fields.Selection([
        ('view', 'Page View'),
        ('success', 'Successful Upload'),
        ('validation_error', 'Validation Error'),
        ('blocked', 'Rate Limited'),
        ('invalid', 'Invalid Link'),
    ], required=True)
    summary = fields.Char()
