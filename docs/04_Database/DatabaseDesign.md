# SmartServe Document Collection Database Design

## Customer

- Name
- Company
- Mobile
- Email
- Emirates ID
- Passport
- Notes
- Multiple Requests

Customers do not log in and are not Laravel portal users.

---

## Service Template

- Service Name
- WhatsApp Template
- Due Days
- Folder Naming Rules
- Upload Rules
- Required Document Templates

Example templates:

- Trade License Renewal
- Visa Renewal
- VAT Registration
- Corporate Tax
- Medical License
- Employee Onboarding

---

## Document Request

- Reference Number
- Request Title
- Customer
- Service Template
- Status
- Due Date
- Assigned Staff
- Secure Upload Token
- Upload Expiry
- Revoked Flag
- Allow Multiple Uploads
- SharePoint Folder ID
- SharePoint Folder URL
- Communication Status
- Internal Notes
- Timeline

---

## Required Document

- Request
- Name
- Description
- Required or Optional
- Allowed Extensions
- Maximum Size
- Upload Status
- Review Status
- SharePoint File ID
- SharePoint File URL

---

## Uploaded Document Metadata

SmartServe stores metadata only. Actual files remain in the configured storage provider.

- Request
- Required Document
- Storage Provider
- Storage File ID
- Storage URL
- Filename
- MIME Type
- File Size
- Uploaded Date
- Review Status
- Staff Notes

---

## Upload Attempt

- Request
- Token Hash
- IP Address
- User Agent
- Result
- Summary
- Created Date

Used for rate limiting and audit logging.

---

## Activity Timeline

- Request
- Activity Type
- Summary
- Created Date

Every important event should create an audit record.

---

## Workflow

Create Customer

->

Create Request

->

Select Service Template

->

Generate Request

->

Create SharePoint Folder

->

Generate Secure Upload Token

->

Send WhatsApp Link

->

Customer Uploads Documents

->

Store Metadata

->

Staff Review

->

Approve / Reject / Request More Documents

->

Complete Request

---

## Explicitly Not Included

- Customer user accounts
- Customer passwords
- Customer login
- Customer dashboard
- Customer portal document management
