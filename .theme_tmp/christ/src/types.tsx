export type FormElementType = 
  | 'text' 
  | 'number' 
  | 'email' 
  | 'tel' 
  | 'radio' 
  | 'checkbox' 
  | 'select' 
  | 'date' 
  | 'textarea' 
  | 'file' 
  | 'section';

export interface FormElement {
  id: string;
  label: string;
  type: FormElementType;
  required: boolean;
  options?: string[]; // For radio, checkbox, select
  placeholder?: string;
  description?: string;
}

export interface Event {
  id: number;
  name: string;
  venue: string;
  startDate: string;
  endDate: string;
  description: string;
  brochureUrl?: string;
  formConfig: FormElement[];
  createdAt: string;
}

export interface Registration {
  id: number;
  eventId: number;
  formData: Record<string, any>;
  attended: boolean;
  registeredAt: string;
}
