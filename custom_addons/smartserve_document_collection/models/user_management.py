from odoo import Command, _, api, fields, models
from odoo.exceptions import AccessError, ValidationError


ROLE_SELECTION = [
    ('administrator', 'Administrator'),
    ('manager', 'Manager'),
    ('employee', 'Employee'),
]

PERMISSION_FIELDS = (
    'smartserve_can_create_customers',
    'smartserve_can_create_requests',
    'smartserve_can_edit_templates',
    'smartserve_can_reassign_requests',
    'smartserve_can_view_all_requests',
    'smartserve_can_complete_requests',
    'smartserve_can_export_data',
)

ROLE_GROUPS = {
    'administrator': 'smartserve_document_collection.group_smartserve_admin',
    'manager': 'smartserve_document_collection.group_smartserve_manager',
    'employee': 'smartserve_document_collection.group_smartserve_staff',
}


class SmartServeTeam(models.Model):
    _name = 'smartserve.team'
    _description = 'SmartServe Team'
    _order = 'name'

    name = fields.Char(required=True)
    manager_id = fields.Many2one(
        'res.users', domain="[('smartserve_role', 'in', ('administrator', 'manager')), ('active', '=', True)]"
    )
    member_ids = fields.One2many('res.users', 'smartserve_team_id', string='Team Members')
    active = fields.Boolean(default=True)
    notes = fields.Text()
    member_count = fields.Integer(compute='_compute_member_count')

    @api.depends('member_ids', 'member_ids.active')
    def _compute_member_count(self):
        for team in self:
            team.member_count = len(team.member_ids.filtered('active'))


class SmartServeRoleProfile(models.Model):
    _name = 'smartserve.role.profile'
    _description = 'SmartServe Role and Permissions'
    _order = 'sequence, id'

    name = fields.Char(required=True, translate=True)
    role_code = fields.Selection(ROLE_SELECTION, required=True, readonly=True)
    sequence = fields.Integer(default=10)
    description = fields.Text()
    can_create_customers = fields.Boolean(string='Can Create Customers')
    can_create_requests = fields.Boolean(string='Can Create Requests')
    can_edit_templates = fields.Boolean(string='Can Edit Templates')
    can_reassign_requests = fields.Boolean(string='Can Reassign Requests')
    can_view_all_requests = fields.Boolean(string='Can View All Requests')
    can_complete_requests = fields.Boolean(string='Can Complete Requests')
    can_export_data = fields.Boolean(string='Can Export Data')

    _sql_constraints = [
        ('smartserve_role_code_unique', 'unique(role_code)', 'Each SmartServe role can only be configured once.'),
    ]

    def unlink(self):
        raise ValidationError(_('The three primary SmartServe roles cannot be deleted.'))

    def write(self, vals):
        result = super().write(vals)
        permission_names = {
            'can_create_customers', 'can_create_requests', 'can_edit_templates',
            'can_reassign_requests', 'can_view_all_requests',
            'can_complete_requests', 'can_export_data',
        }
        if permission_names.intersection(vals):
            audit = self.env['smartserve.user.audit'].sudo()
            for role in self:
                audit.create({
                    'actor_id': self.env.user.id,
                    'user_id': self.env.user.id,
                    'event_type': 'permissions_changed',
                    'description': _('Default permissions updated for the %s role.') % role.name,
                })
        return result


class SmartServeUserAudit(models.Model):
    _name = 'smartserve.user.audit'
    _description = 'SmartServe User Activity Log'
    _order = 'event_date desc, id desc'

    event_date = fields.Datetime(default=fields.Datetime.now, required=True, readonly=True)
    actor_id = fields.Many2one('res.users', required=True, readonly=True, ondelete='restrict')
    user_id = fields.Many2one('res.users', required=True, readonly=True, ondelete='restrict')
    event_type = fields.Selection([
        ('created', 'User Created'),
        ('role_changed', 'Role Changed'),
        ('permissions_changed', 'Permissions Changed'),
        ('activated', 'User Reactivated'),
        ('deactivated', 'User Deactivated'),
        ('invitation', 'Invitation / Password Reset Sent'),
    ], required=True, readonly=True)
    description = fields.Char(required=True, readonly=True)

    def write(self, vals):
        raise AccessError(_('Activity log entries cannot be changed.'))

    def unlink(self):
        raise AccessError(_('Activity log entries cannot be deleted.'))


class ResUsers(models.Model):
    _inherit = 'res.users'

    smartserve_role = fields.Selection(ROLE_SELECTION, string='Role', index=True)
    smartserve_team_id = fields.Many2one('smartserve.team', string='Team', ondelete='set null')
    smartserve_can_create_customers = fields.Boolean(string='Can Create Customers')
    smartserve_can_create_requests = fields.Boolean(string='Can Create Requests')
    smartserve_can_edit_templates = fields.Boolean(string='Can Edit Templates')
    smartserve_can_reassign_requests = fields.Boolean(string='Can Reassign Requests')
    smartserve_can_view_all_requests = fields.Boolean(string='Can View All Requests')
    smartserve_can_complete_requests = fields.Boolean(string='Can Complete Requests')
    smartserve_can_export_data = fields.Boolean(string='Can Export Data')
    smartserve_assigned_request_count = fields.Integer(
        string='Assigned Requests', compute='_compute_smartserve_assigned_request_count'
    )

    def init(self):
        """Assign business roles once to users already carrying legacy SmartServe groups."""
        migrated_ids = []
        for role, xmlid in (
            ('administrator', 'smartserve_document_collection.group_smartserve_admin'),
            ('manager', 'smartserve_document_collection.group_smartserve_manager'),
            ('employee', 'smartserve_document_collection.group_smartserve_staff'),
        ):
            group = self.env.ref(xmlid, raise_if_not_found=False)
            if not group:
                continue
            self.env.cr.execute("""
                UPDATE res_users
                   SET smartserve_role = %s
                 WHERE smartserve_role IS NULL
                   AND id IN (SELECT uid FROM res_groups_users_rel WHERE gid = %s)
             RETURNING id
            """, (role, group.id))
            migrated_ids.extend(row[0] for row in self.env.cr.fetchall())
        role_defaults = {
            'administrator': (True, True, True, True, True, True, True),
            'manager': (True, True, True, True, False, True, True),
            'employee': (False, True, False, False, False, True, False),
        }
        for user in self.sudo().browse(migrated_ids):
            values = dict(zip(PERMISSION_FIELDS, role_defaults[user.smartserve_role]))
            columns = ', '.join('%s = %%s' % name for name in values)
            self.env.cr.execute(
                'UPDATE res_users SET %s WHERE id = %%s' % columns,
                tuple(values.values()) + (user.id,),
            )

    @api.depends('smartserve_role')
    def _compute_smartserve_assigned_request_count(self):
        request_model = self.env['smartserve.document.request'].sudo()
        for user in self:
            user.smartserve_assigned_request_count = request_model.search_count([('assigned_user_id', '=', user.id)])

    def _smartserve_is_admin(self):
        return self.env.is_superuser() or self.env.user.has_group(
            'smartserve_document_collection.group_smartserve_admin'
        )

    def _smartserve_role_defaults(self, role):
        profile = self.env['smartserve.role.profile'].sudo().search([('role_code', '=', role)], limit=1)
        if not profile:
            defaults = {
                'administrator': (True, True, True, True, True, True, True),
                'manager': (True, True, True, True, False, True, True),
                'employee': (False, True, False, False, False, True, False),
            }.get(role, (False,) * 7)
            return dict(zip(PERMISSION_FIELDS, defaults))
        return {
            field_name: profile[profile_name]
            for field_name, profile_name in zip(PERMISSION_FIELDS, (
                'can_create_customers', 'can_create_requests', 'can_edit_templates',
                'can_reassign_requests', 'can_view_all_requests',
                'can_complete_requests', 'can_export_data',
            ))
        }

    def _smartserve_sync_role_group(self, role):
        smartserve_groups = self.env['res.groups'].sudo().browse([
            self.env.ref(xmlid).id for xmlid in ROLE_GROUPS.values()
        ])
        target = self.env.ref(ROLE_GROUPS[role]) if role else self.env['res.groups']
        for user in self:
            commands = [Command.unlink(group.id) for group in smartserve_groups]
            if target:
                commands.append(Command.link(target.id))
                commands.append(Command.link(self.env.ref('base.group_user').id))
            user.sudo().with_context(smartserve_role_sync=True).write({'groups_id': commands})

    def _smartserve_sync_export_group(self):
        export_group = self.env.ref('base.group_allow_export', raise_if_not_found=False)
        if not export_group:
            return
        for user in self:
            command = Command.link(export_group.id) if user.smartserve_can_export_data else Command.unlink(export_group.id)
            user.sudo().with_context(smartserve_role_sync=True).write({'groups_id': [command]})

    def _smartserve_log(self, event_type, description):
        audit = self.env['smartserve.user.audit'].sudo()
        for user in self:
            audit.create({
                'actor_id': self.env.user.id,
                'user_id': user.id,
                'event_type': event_type,
                'description': description,
            })

    def _smartserve_check_last_admin(self, vals):
        removing = vals.get('active') is False or (
            'smartserve_role' in vals and vals.get('smartserve_role') != 'administrator'
        )
        if not removing:
            return
        active_admins = self.sudo().search_count([
            ('active', '=', True), ('smartserve_role', '=', 'administrator'), ('id', 'not in', self.ids)
        ])
        if not active_admins and self.filtered(lambda user: user.active and user.smartserve_role == 'administrator'):
            raise ValidationError(_('You cannot deactivate or change the role of the last active Administrator.'))

    @api.model_create_multi
    def create(self, vals_list):
        for vals in vals_list:
            role = vals.get('smartserve_role')
            if role and not self._smartserve_is_admin():
                raise AccessError(_('Only a SmartServe Administrator can create SmartServe users.'))
            if role == 'administrator' and not self._smartserve_is_admin():
                raise AccessError(_('Only an Administrator can create another Administrator.'))
            if role:
                defaults = self._smartserve_role_defaults(role)
                for key, value in defaults.items():
                    vals.setdefault(key, value)
        users = super().create(vals_list)
        for user in users.filtered('smartserve_role'):
            user._smartserve_sync_role_group(user.smartserve_role)
            user._smartserve_sync_export_group()
            user._smartserve_log('created', _('User created with role %s.') % dict(ROLE_SELECTION)[user.smartserve_role])
        return users

    def write(self, vals):
        tracked = {'smartserve_role', 'smartserve_team_id', 'active', *PERMISSION_FIELDS}
        if tracked.intersection(vals) and not self._smartserve_is_admin():
            raise AccessError(_('Only a SmartServe Administrator can manage users and permissions.'))
        if vals.get('smartserve_role') == 'administrator' and not self._smartserve_is_admin():
            raise AccessError(_('Only an Administrator can assign the Administrator role.'))
        self._smartserve_check_last_admin(vals)
        old_roles = {user.id: user.smartserve_role for user in self}
        old_active = {user.id: user.active for user in self}
        if 'smartserve_role' in vals:
            defaults = self._smartserve_role_defaults(vals['smartserve_role'])
            for key, value in defaults.items():
                vals.setdefault(key, value)
        result = super().write(vals)
        if self.env.context.get('smartserve_role_sync'):
            return result
        if 'smartserve_role' in vals:
            self._smartserve_sync_role_group(vals['smartserve_role'])
            for user in self:
                user._smartserve_log(
                    'role_changed', _('Role changed from %s to %s.') %
                    (dict(ROLE_SELECTION).get(old_roles[user.id], _('None')),
                     dict(ROLE_SELECTION).get(user.smartserve_role, _('None')))
                )
        if set(PERMISSION_FIELDS).intersection(vals):
            if 'smartserve_can_export_data' in vals or 'smartserve_role' in vals:
                self._smartserve_sync_export_group()
            self._smartserve_log('permissions_changed', _('User permissions were updated.'))
        if 'active' in vals:
            for user in self:
                if old_active[user.id] != user.active:
                    user._smartserve_log('activated' if user.active else 'deactivated',
                                         _('User reactivated.') if user.active else _('User deactivated.'))
        return result

    def action_smartserve_deactivate(self):
        self.write({'active': False})
        return True

    def action_smartserve_reactivate(self):
        self.with_context(active_test=False).write({'active': True})
        return True

    def action_smartserve_send_invitation(self):
        self.ensure_one()
        if not self._smartserve_is_admin():
            raise AccessError(_('Only an Administrator can send invitations or password resets.'))
        result = self.action_reset_password()
        self._smartserve_log('invitation', _('Invitation / password reset email sent.'))
        return result

    def action_smartserve_assigned_requests(self):
        self.ensure_one()
        action = self.env['ir.actions.actions']._for_xml_id(
            'smartserve_document_collection.action_smartserve_document_requests'
        )
        action['domain'] = [('assigned_user_id', '=', self.id)]
        action['context'] = {'default_assigned_user_id': self.id}
        return action


class ResConfigSettings(models.TransientModel):
    _inherit = 'res.config.settings'

    smartserve_default_upload_expiry_days = fields.Integer(
        string='Default Upload Link Expiry (Days)', config_parameter='smartserve.default_upload_expiry_days', default=7
    )
    smartserve_storage_provider = fields.Char(
        string='Document Storage Provider', config_parameter='smartserve.storage_provider', default='local'
    )
    smartserve_communication_provider = fields.Char(
        string='Communication Provider', config_parameter='smartserve.communication_provider', default='log'
    )
